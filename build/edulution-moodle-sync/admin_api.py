#!/usr/bin/env python3
"""
admin_api.py - Management API fuer Moodle-Admin-UI

Vollstaendige REST-API fuer die Edulution Moodle Admin-Oberflaeche.
Bietet Endpoints fuer Dashboard, Sync, Plugins, Backups, Users, Logs,
Settings und Maintenance.

Erreichbar unter /moodle-admin via Traefik.
"""

from fastapi import FastAPI, HTTPException, Depends, BackgroundTasks, Query
from fastapi.staticfiles import StaticFiles
from fastapi.responses import HTMLResponse, FileResponse, JSONResponse
from fastapi.security import HTTPBasic, HTTPBasicCredentials
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field
from typing import List, Dict, Optional, Any
import subprocess
import json
import os
import secrets
import shutil
import asyncio
import logging
from datetime import datetime, timedelta
from pathlib import Path
from contextlib import asynccontextmanager

# =============================================================================
# LOGGING SETUP
# =============================================================================

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger("admin_api")

# =============================================================================
# CONFIGURATION
# =============================================================================

# Pfade
MOODLE_PATH = os.getenv("MOODLE_PATH", "/var/www/html/moodle")
DATA_PATH = os.getenv("SYNC_DATA_PATH", "/srv/data")
BACKUP_PATH = os.getenv("MOODLE_BACKUP_PATH", "/srv/backups")
LOG_PATH = os.getenv("MOODLE_LOG_PATH", "/var/log/moodle-sync")
ADMIN_UI_PATH = os.getenv("ADMIN_UI_PATH", "/opt/admin-ui")

# Security
ADMIN_USER = os.getenv("ADMIN_UI_USER", "admin")
ADMIN_PASS = os.getenv("ADMIN_UI_PASSWORD", os.getenv("MOODLE_ADMIN_PASSWORD", "admin"))

# API Settings
API_RATE_LIMIT = int(os.getenv("ADMIN_API_RATE_LIMIT", "100"))

# =============================================================================
# APP INITIALIZATION
# =============================================================================

@asynccontextmanager
async def lifespan(app: FastAPI):
    """Lifecycle management"""
    logger.info("Admin API starting up...")
    # Ensure directories exist
    for path in [DATA_PATH, LOG_PATH]:
        Path(path).mkdir(parents=True, exist_ok=True)
    yield
    logger.info("Admin API shutting down...")

app = FastAPI(
    title="Edulution Moodle Admin",
    description="Management API fuer Moodle-Sync und Wartung",
    version="1.0.0",
    docs_url="/api/docs",
    redoc_url="/api/redoc",
    lifespan=lifespan
)

# CORS Middleware
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Security
security = HTTPBasic()

def verify_credentials(credentials: HTTPBasicCredentials = Depends(security)) -> str:
    """Basic Auth Verifizierung"""
    is_user = secrets.compare_digest(credentials.username, ADMIN_USER)
    is_pass = secrets.compare_digest(credentials.password, ADMIN_PASS)
    if not (is_user and is_pass):
        raise HTTPException(
            status_code=401,
            detail="Invalid credentials",
            headers={"WWW-Authenticate": "Basic"},
        )
    return credentials.username


# =============================================================================
# PYDANTIC MODELS
# =============================================================================

class SyncStatus(BaseModel):
    running: bool = False
    last_run: Optional[str] = None
    last_success: Optional[str] = None
    users_synced: int = 0
    courses_synced: int = 0
    errors: int = 0
    status: str = "unknown"

class PluginInfo(BaseModel):
    component: str
    name: str
    version: str
    installed: bool = True
    update_available: bool = False
    description: Optional[str] = None
    requires: Optional[str] = None

class BackupInfo(BaseModel):
    filename: str
    created: str
    size: str
    type: str
    path: Optional[str] = None

class UserSyncInfo(BaseModel):
    username: str
    email: str
    status: str  # synced, pending, failed, protected
    last_sync: Optional[str] = None
    moodle_id: Optional[int] = None
    firstname: Optional[str] = None
    lastname: Optional[str] = None

class LogEntry(BaseModel):
    timestamp: str
    level: str
    message: str
    module: Optional[str] = None

class HealthCheck(BaseModel):
    status: str
    checks: Dict[str, bool]
    timestamp: str

class DashboardResponse(BaseModel):
    moodle: Dict[str, Any]
    sync: Dict[str, Any]
    keycloak: Dict[str, Any]
    system: Dict[str, Any]
    recent_errors: List[Dict[str, Any]]

class SyncTriggerRequest(BaseModel):
    full: bool = False

class BackupCreateRequest(BaseModel):
    backup_type: str = Field(default="full", pattern="^(full|quick|db-only)$")

class SettingsUpdateRequest(BaseModel):
    settings: Dict[str, Any]


# =============================================================================
# HELPER FUNCTIONS
# =============================================================================

def _get_moodle_version() -> str:
    """Ermittelt Moodle-Version"""
    try:
        version_file = os.path.join(MOODLE_PATH, "version.php")
        if os.path.exists(version_file):
            result = subprocess.run(
                ["grep", "-oP", r"\$release\s*=\s*'\K[^']+", version_file],
                capture_output=True, text=True, timeout=5
            )
            if result.returncode == 0:
                return result.stdout.strip()
        return "unknown"
    except Exception as e:
        logger.error(f"Error getting Moodle version: {e}")
        return "unknown"

def _check_moodle_health() -> bool:
    """Prueft Moodle-Webserver"""
    try:
        result = subprocess.run(
            ["curl", "-sf", "-o", "/dev/null", "-w", "%{http_code}",
             "http://localhost/login/index.php"],
            capture_output=True, text=True, timeout=10
        )
        return result.stdout.strip() == "200"
    except Exception as e:
        logger.error(f"Moodle health check failed: {e}")
        return False

def _check_database() -> bool:
    """Prueft Datenbankverbindung"""
    try:
        result = subprocess.run(
            ["php", "-r", """
            define('CLI_SCRIPT', true);
            require('/var/www/html/moodle/config.php');
            global $DB;
            $DB->get_record_sql('SELECT 1');
            echo 'OK';
            """],
            capture_output=True, text=True, timeout=10
        )
        return "OK" in result.stdout
    except Exception as e:
        logger.error(f"Database check failed: {e}")
        return False

def _check_keycloak_connection() -> bool:
    """Prueft Keycloak-Verbindung"""
    kc_url = os.getenv("KEYCLOAK_SERVER_URL")
    if not kc_url:
        return True  # Nicht konfiguriert = OK
    try:
        realm = os.getenv("KEYCLOAK_REALM", "master")
        url = f"{kc_url.rstrip('/')}/realms/{realm}"
        result = subprocess.run(
            ["curl", "-sf", "-o", "/dev/null", url],
            capture_output=True, timeout=10
        )
        return result.returncode == 0
    except Exception as e:
        logger.error(f"Keycloak check failed: {e}")
        return False

def _check_redis() -> bool:
    """Prueft Redis-Verbindung"""
    redis_host = os.getenv("REDIS_HOST")
    if not redis_host:
        return True  # Nicht konfiguriert = OK
    try:
        result = subprocess.run(
            ["redis-cli", "-h", redis_host, "ping"],
            capture_output=True, text=True, timeout=5
        )
        return "PONG" in result.stdout
    except Exception as e:
        logger.error(f"Redis check failed: {e}")
        return False

def _get_uptime() -> str:
    """Ermittelt Container-Uptime"""
    try:
        result = subprocess.run(["cat", "/proc/uptime"], capture_output=True, text=True)
        seconds = float(result.stdout.split()[0])
        days, remainder = divmod(int(seconds), 86400)
        hours, remainder = divmod(remainder, 3600)
        minutes, _ = divmod(remainder, 60)
        return f"{days}d {hours}h {minutes}m"
    except:
        return "unknown"

def _get_disk_usage() -> Dict[str, str]:
    """Ermittelt Disk-Usage"""
    try:
        result = subprocess.run(
            ["df", "-h", MOODLE_PATH],
            capture_output=True, text=True
        )
        lines = result.stdout.strip().split('\n')
        if len(lines) >= 2:
            parts = lines[1].split()
            return {
                "total": parts[1],
                "used": parts[2],
                "available": parts[3],
                "percent": parts[4]
            }
    except:
        pass
    return {"total": "unknown", "used": "unknown", "available": "unknown", "percent": "unknown"}

def _get_memory_usage() -> Dict[str, str]:
    """Ermittelt Memory-Usage"""
    try:
        result = subprocess.run(
            ["free", "-h"],
            capture_output=True, text=True
        )
        lines = result.stdout.strip().split('\n')
        if len(lines) >= 2:
            parts = lines[1].split()
            return {
                "total": parts[1],
                "used": parts[2],
                "free": parts[3],
                "available": parts[6] if len(parts) > 6 else parts[3]
            }
    except:
        pass
    return {"total": "unknown", "used": "unknown", "free": "unknown", "available": "unknown"}

def _count_moodle_users() -> int:
    """Zaehlt Moodle-User"""
    try:
        result = subprocess.run(
            ["php", "-r", """
            define('CLI_SCRIPT', true);
            require('/var/www/html/moodle/config.php');
            global $DB;
            echo $DB->count_records('user', ['deleted' => 0]);
            """],
            capture_output=True, text=True, timeout=10
        )
        return int(result.stdout.strip())
    except:
        return 0

def _count_moodle_courses() -> int:
    """Zaehlt Moodle-Kurse"""
    try:
        result = subprocess.run(
            ["php", "-r", """
            define('CLI_SCRIPT', true);
            require('/var/www/html/moodle/config.php');
            global $DB;
            echo $DB->count_records('course') - 1;  // Minus Site-Course
            """],
            capture_output=True, text=True, timeout=10
        )
        return max(0, int(result.stdout.strip()))
    except:
        return 0

def _get_sync_status() -> str:
    """Ermittelt aktuellen Sync-Status"""
    state_file = os.path.join(DATA_PATH, "sync_state.json")
    disable_file = os.path.join(DATA_PATH, "DISABLE_SYNC")

    if os.path.exists(disable_file):
        return "paused"

    if os.path.exists(state_file):
        try:
            with open(state_file, 'r') as f:
                state = json.load(f)
                return state.get("status", "idle")
        except:
            pass
    return "idle"

def _get_last_sync_time() -> Optional[str]:
    """Ermittelt letzten Sync-Zeitpunkt"""
    state_file = os.path.join(DATA_PATH, "sync_state.json")
    if os.path.exists(state_file):
        try:
            with open(state_file, 'r') as f:
                state = json.load(f)
                return state.get("last_run")
        except:
            pass
    return None

def _get_recent_errors(count: int = 5) -> List[Dict[str, Any]]:
    """Holt die letzten Fehler"""
    errors = []
    error_log = os.path.join(LOG_PATH, "error.log")

    if os.path.exists(error_log):
        try:
            result = subprocess.run(
                ["tail", "-n", "100", error_log],
                capture_output=True, text=True
            )
            lines = result.stdout.strip().split('\n')
            for line in reversed(lines):
                if line and len(errors) < count:
                    try:
                        entry = json.loads(line)
                        if entry.get("level") in ["ERROR", "CRITICAL"]:
                            errors.append(entry)
                    except:
                        if "error" in line.lower() or "exception" in line.lower():
                            errors.append({
                                "timestamp": datetime.now().isoformat(),
                                "level": "ERROR",
                                "message": line[:200]
                            })
        except Exception as e:
            logger.error(f"Error reading error log: {e}")

    return errors

def _read_log_file(path: str, lines: int, level: str = "all") -> List[Dict[str, Any]]:
    """Liest Log-Datei und gibt Eintraege zurueck"""
    entries = []
    if os.path.exists(path):
        try:
            result = subprocess.run(
                ["tail", "-n", str(lines), path],
                capture_output=True, text=True
            )
            for line in result.stdout.strip().split('\n'):
                if not line:
                    continue
                try:
                    entry = json.loads(line)
                    if level == "all" or entry.get("level", "").upper() == level.upper():
                        entries.append(entry)
                except json.JSONDecodeError:
                    # Plain text log line
                    entry = {
                        "timestamp": datetime.now().isoformat(),
                        "level": "INFO",
                        "message": line,
                        "module": "unknown"
                    }
                    if level == "all":
                        entries.append(entry)
        except Exception as e:
            logger.error(f"Error reading log file {path}: {e}")
    return entries

def _get_plugin_list() -> List[Dict[str, Any]]:
    """Listet installierte Plugins"""
    plugins = []
    plugin_state_file = os.path.join(DATA_PATH, "plugin_state.json")

    # Aus Plugin-State laden
    if os.path.exists(plugin_state_file):
        try:
            with open(plugin_state_file, 'r') as f:
                state = json.load(f)
                for component, info in state.get("plugins", {}).items():
                    plugins.append({
                        "component": component,
                        "name": info.get("name", component),
                        "version": info.get("version", "unknown"),
                        "installed": True,
                        "update_available": info.get("update_available", False),
                        "description": info.get("description", ""),
                        "requires": info.get("requires")
                    })
        except Exception as e:
            logger.error(f"Error loading plugin state: {e}")

    # Fallback: Aus Moodle-Verzeichnis lesen
    if not plugins:
        plugin_types = ["mod", "block", "local", "auth", "enrol", "theme", "format"]
        for ptype in plugin_types:
            plugin_dir = os.path.join(MOODLE_PATH, ptype)
            if os.path.isdir(plugin_dir):
                for name in os.listdir(plugin_dir):
                    if name.startswith('.'):
                        continue
                    version_file = os.path.join(plugin_dir, name, "version.php")
                    if os.path.exists(version_file):
                        plugins.append({
                            "component": f"{ptype}_{name}",
                            "name": name,
                            "version": "installed",
                            "installed": True,
                            "update_available": False
                        })

    return plugins

def _get_user_list(status: str, limit: int, offset: int, search: str) -> Dict[str, Any]:
    """Holt User-Liste aus Sync-State und Moodle"""
    users = []
    total = 0

    # Aus Sync-State laden
    sync_state_file = os.path.join(DATA_PATH, "sync_state.json")
    if os.path.exists(sync_state_file):
        try:
            with open(sync_state_file, 'r') as f:
                state = json.load(f)
                user_states = state.get("users", {})

                for username, info in user_states.items():
                    user_status = info.get("status", "synced")

                    # Filter by status
                    if status != "all" and user_status != status:
                        continue

                    # Filter by search
                    if search and search.lower() not in username.lower():
                        email = info.get("email", "")
                        if search.lower() not in email.lower():
                            continue

                    users.append({
                        "username": username,
                        "email": info.get("email", ""),
                        "status": user_status,
                        "last_sync": info.get("last_sync"),
                        "moodle_id": info.get("moodle_id"),
                        "firstname": info.get("firstname", ""),
                        "lastname": info.get("lastname", "")
                    })
                    total += 1
        except Exception as e:
            logger.error(f"Error loading sync state: {e}")

    # Pagination
    users = users[offset:offset + limit]

    return {
        "users": users,
        "total": total,
        "limit": limit,
        "offset": offset
    }

def _get_user_details(username: str) -> Dict[str, Any]:
    """Holt Details zu einem User"""
    sync_state_file = os.path.join(DATA_PATH, "sync_state.json")
    user_info = {"username": username, "status": "unknown"}

    if os.path.exists(sync_state_file):
        try:
            with open(sync_state_file, 'r') as f:
                state = json.load(f)
                user_states = state.get("users", {})
                if username in user_states:
                    user_info = user_states[username]
                    user_info["username"] = username
        except Exception as e:
            logger.error(f"Error loading user details: {e}")

    return user_info


# =============================================================================
# BACKGROUND TASKS
# =============================================================================

async def _run_sync(full: bool = False):
    """Fuehrt Sync im Hintergrund aus"""
    logger.info(f"Starting {'full' if full else 'incremental'} sync")
    try:
        cmd = ["/opt/sync-venv/bin/python", "/opt/edulution-moodle-sync/sync.py", "--single-run"]
        if full:
            cmd.append("--full")
        process = await asyncio.create_subprocess_exec(
            *cmd,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE
        )
        stdout, stderr = await process.communicate()
        logger.info(f"Sync completed with return code {process.returncode}")
    except Exception as e:
        logger.error(f"Sync failed: {e}")

async def _sync_single_user(username: str):
    """Synchronisiert einzelnen User"""
    logger.info(f"Syncing single user: {username}")
    try:
        cmd = ["/opt/sync-venv/bin/python", "/opt/edulution-moodle-sync/sync.py",
               "--single-run", "--user", username]
        process = await asyncio.create_subprocess_exec(
            *cmd,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE
        )
        await process.communicate()
    except Exception as e:
        logger.error(f"Single user sync failed: {e}")

async def _create_backup(backup_type: str):
    """Erstellt Backup im Hintergrund"""
    logger.info(f"Creating {backup_type} backup")
    try:
        process = await asyncio.create_subprocess_exec(
            "/opt/scripts/backup.sh", f"--{backup_type}",
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE
        )
        await process.communicate()
        logger.info("Backup completed")
    except Exception as e:
        logger.error(f"Backup failed: {e}")

async def _restore_backup(backup_name: str):
    """Stellt Backup wieder her"""
    logger.info(f"Restoring backup: {backup_name}")
    try:
        process = await asyncio.create_subprocess_exec(
            "/opt/scripts/backup.sh", "--restore", backup_name,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE
        )
        await process.communicate()
        logger.info("Restore completed")
    except Exception as e:
        logger.error(f"Restore failed: {e}")

async def _install_plugin(component: str):
    """Installiert Plugin im Hintergrund"""
    logger.info(f"Installing plugin: {component}")
    try:
        process = await asyncio.create_subprocess_exec(
            "/opt/sync-venv/bin/python", "/opt/scripts/plugin_manager.py",
            "install", component,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE
        )
        await process.communicate()
        logger.info(f"Plugin {component} installed")
    except Exception as e:
        logger.error(f"Plugin installation failed: {e}")

async def _update_all_plugins():
    """Aktualisiert alle Plugins"""
    logger.info("Updating all plugins")
    try:
        process = await asyncio.create_subprocess_exec(
            "/opt/sync-venv/bin/python", "/opt/scripts/plugin_manager.py",
            "update", "--all",
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE
        )
        await process.communicate()
        logger.info("All plugins updated")
    except Exception as e:
        logger.error(f"Plugin update failed: {e}")

async def _uninstall_plugin(component: str):
    """Deinstalliert Plugin"""
    logger.info(f"Uninstalling plugin: {component}")
    try:
        process = await asyncio.create_subprocess_exec(
            "/opt/sync-venv/bin/python", "/opt/scripts/plugin_manager.py",
            "uninstall", component,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE
        )
        await process.communicate()
        logger.info(f"Plugin {component} uninstalled")
    except Exception as e:
        logger.error(f"Plugin uninstallation failed: {e}")

async def _run_moodle_cron():
    """Fuehrt Moodle Cron aus"""
    logger.info("Running Moodle cron")
    try:
        process = await asyncio.create_subprocess_exec(
            "php", f"{MOODLE_PATH}/admin/cli/cron.php",
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE
        )
        await process.communicate()
        logger.info("Cron completed")
    except Exception as e:
        logger.error(f"Cron failed: {e}")


# =============================================================================
# DASHBOARD ENDPOINTS
# =============================================================================

@app.get("/api/dashboard", tags=["Dashboard"], response_model=DashboardResponse)
async def get_dashboard(user: str = Depends(verify_credentials)):
    """Holt Dashboard-Uebersicht mit allen wichtigen Metriken"""
    return {
        "moodle": {
            "version": _get_moodle_version(),
            "status": "healthy" if _check_moodle_health() else "unhealthy",
            "url": os.getenv("MOODLE_WWWROOT", ""),
            "users_total": _count_moodle_users(),
            "courses_total": _count_moodle_courses(),
        },
        "sync": {
            "enabled": os.getenv("SYNC_ENABLED", "1") == "1",
            "status": _get_sync_status(),
            "last_run": _get_last_sync_time(),
            "interval": int(os.getenv("SYNC_INTERVAL", "300")),
        },
        "keycloak": {
            "connected": _check_keycloak_connection(),
            "url": os.getenv("KEYCLOAK_SERVER_URL", ""),
            "realm": os.getenv("KEYCLOAK_REALM", ""),
        },
        "system": {
            "uptime": _get_uptime(),
            "disk_usage": _get_disk_usage(),
            "memory_usage": _get_memory_usage(),
            "container_health": "healthy",
        },
        "recent_errors": _get_recent_errors(5),
    }


@app.get("/api/health", tags=["Dashboard"], response_model=HealthCheck)
async def get_health():
    """Health-Check Endpoint (ohne Auth fuer Monitoring)"""
    checks = {
        "moodle_web": _check_moodle_health(),
        "database": _check_database(),
        "keycloak": _check_keycloak_connection(),
        "redis": _check_redis(),
    }
    all_healthy = all(checks.values())
    return {
        "status": "healthy" if all_healthy else "degraded",
        "checks": checks,
        "timestamp": datetime.utcnow().isoformat()
    }


# =============================================================================
# SYNC ENDPOINTS
# =============================================================================

@app.get("/api/sync/status", tags=["Sync"])
async def get_sync_status_endpoint(user: str = Depends(verify_credentials)):
    """Holt aktuellen Sync-Status"""
    state_file = os.path.join(DATA_PATH, "sync_state.json")
    if os.path.exists(state_file):
        try:
            with open(state_file, 'r') as f:
                return json.load(f)
        except:
            pass
    return {"status": "unknown", "last_run": None}


@app.post("/api/sync/trigger", tags=["Sync"])
async def trigger_sync(
    background_tasks: BackgroundTasks,
    full: bool = Query(False, description="Full sync instead of incremental"),
    user: str = Depends(verify_credentials)
):
    """Triggert manuellen Sync-Lauf"""
    background_tasks.add_task(_run_sync, full)
    return {"message": "Sync triggered", "full": full, "timestamp": datetime.utcnow().isoformat()}


@app.get("/api/sync/logs", tags=["Sync"])
async def get_sync_logs(
    lines: int = Query(100, ge=1, le=1000),
    level: str = Query("all", pattern="^(all|DEBUG|INFO|WARNING|ERROR|CRITICAL)$"),
    user: str = Depends(verify_credentials)
):
    """Holt Sync-Log-Eintraege"""
    log_file = os.path.join(LOG_PATH, "sync.log")
    return _read_log_file(log_file, lines, level)


@app.get("/api/sync/config", tags=["Sync"])
async def get_sync_config(user: str = Depends(verify_credentials)):
    """Holt aktuelle Sync-Konfiguration"""
    return {
        "sync_interval": int(os.getenv("SYNC_INTERVAL", "300")),
        "groups_to_sync": [g.strip() for g in os.getenv("GROUPS_TO_SYNC", "").split(",") if g.strip()],
        "sync_all_users": os.getenv("SYNC_ALL_USERS", "0") == "1",
        "soft_delete_enabled": os.getenv("SOFT_DELETE_ENABLED", "1") == "1",
        "soft_delete_grace_period": int(os.getenv("SOFT_DELETE_GRACE_PERIOD", "2592000")),
        "delete_enabled": os.getenv("DELETE_ENABLED", "0") == "1",
        "role_mappings": {
            "student": [g.strip() for g in os.getenv("ROLE_STUDENT_GROUPS", "").split(",") if g.strip()],
            "teacher": [g.strip() for g in os.getenv("ROLE_TEACHER_GROUPS", "").split(",") if g.strip()],
            "manager": [g.strip() for g in os.getenv("ROLE_MANAGER_GROUPS", "").split(",") if g.strip()],
        }
    }


@app.post("/api/sync/pause", tags=["Sync"])
async def pause_sync(user: str = Depends(verify_credentials)):
    """Pausiert den Sync-Prozess temporaer"""
    disable_file = os.path.join(DATA_PATH, "DISABLE_SYNC")
    with open(disable_file, 'w') as f:
        f.write(datetime.utcnow().isoformat())
    logger.info(f"Sync paused by {user}")
    return {"message": "Sync paused", "timestamp": datetime.utcnow().isoformat()}


@app.post("/api/sync/resume", tags=["Sync"])
async def resume_sync(user: str = Depends(verify_credentials)):
    """Nimmt den Sync-Prozess wieder auf"""
    disable_file = os.path.join(DATA_PATH, "DISABLE_SYNC")
    if os.path.exists(disable_file):
        os.remove(disable_file)
    logger.info(f"Sync resumed by {user}")
    return {"message": "Sync resumed", "timestamp": datetime.utcnow().isoformat()}


# =============================================================================
# USER ENDPOINTS
# =============================================================================

@app.get("/api/users", tags=["Users"])
async def get_users(
    status: str = Query("all", pattern="^(all|synced|pending|failed|protected)$"),
    limit: int = Query(100, ge=1, le=1000),
    offset: int = Query(0, ge=0),
    search: str = Query(""),
    user: str = Depends(verify_credentials)
):
    """Listet synchronisierte/ausstehende User"""
    return _get_user_list(status, limit, offset, search)


@app.get("/api/users/{username}", tags=["Users"])
async def get_user_details_endpoint(username: str, user: str = Depends(verify_credentials)):
    """Holt Details zu einem bestimmten User"""
    user_info = _get_user_details(username)
    if user_info.get("status") == "unknown":
        raise HTTPException(status_code=404, detail=f"User {username} not found")
    return user_info


@app.post("/api/users/{username}/sync", tags=["Users"])
async def force_sync_user(
    username: str,
    background_tasks: BackgroundTasks,
    user: str = Depends(verify_credentials)
):
    """Erzwingt Sync fuer einzelnen User"""
    background_tasks.add_task(_sync_single_user, username)
    return {"message": f"Sync triggered for {username}", "timestamp": datetime.utcnow().isoformat()}


@app.post("/api/users/{username}/protect", tags=["Users"])
async def protect_user(username: str, user: str = Depends(verify_credentials)):
    """Schuetzt User vor automatischer Loeschung"""
    # Zu PROTECTED_USERS hinzufuegen
    protected_file = os.path.join(DATA_PATH, "protected_users.json")
    protected = []

    if os.path.exists(protected_file):
        try:
            with open(protected_file, 'r') as f:
                protected = json.load(f)
        except:
            pass

    if username not in protected:
        protected.append(username)
        with open(protected_file, 'w') as f:
            json.dump(protected, f, indent=2)

    logger.info(f"User {username} protected by {user}")
    return {"message": f"User {username} protected", "timestamp": datetime.utcnow().isoformat()}


@app.delete("/api/users/{username}/protect", tags=["Users"])
async def unprotect_user(username: str, user: str = Depends(verify_credentials)):
    """Entfernt Loeschungsschutz von User"""
    protected_file = os.path.join(DATA_PATH, "protected_users.json")

    if os.path.exists(protected_file):
        try:
            with open(protected_file, 'r') as f:
                protected = json.load(f)
            if username in protected:
                protected.remove(username)
                with open(protected_file, 'w') as f:
                    json.dump(protected, f, indent=2)
        except Exception as e:
            logger.error(f"Error removing user protection: {e}")

    return {"message": f"User {username} unprotected", "timestamp": datetime.utcnow().isoformat()}


# =============================================================================
# PLUGIN ENDPOINTS
# =============================================================================

@app.get("/api/plugins", tags=["Plugins"])
async def get_plugins(user: str = Depends(verify_credentials)):
    """Listet alle installierten Plugins"""
    return _get_plugin_list()


@app.get("/api/plugins/updates", tags=["Plugins"])
async def check_plugin_updates(user: str = Depends(verify_credentials)):
    """Prueft auf verfuegbare Plugin-Updates"""
    plugins = _get_plugin_list()
    updates_available = [p for p in plugins if p.get("update_available")]
    return {
        "total_plugins": len(plugins),
        "updates_available": len(updates_available),
        "plugins_with_updates": updates_available
    }


@app.post("/api/plugins/install/{component}", tags=["Plugins"])
async def install_plugin(
    component: str,
    background_tasks: BackgroundTasks,
    user: str = Depends(verify_credentials)
):
    """Installiert ein neues Plugin"""
    background_tasks.add_task(_install_plugin, component)
    return {"message": f"Installing {component}", "timestamp": datetime.utcnow().isoformat()}


@app.post("/api/plugins/update", tags=["Plugins"])
async def update_all_plugins(
    background_tasks: BackgroundTasks,
    user: str = Depends(verify_credentials)
):
    """Aktualisiert alle Plugins mit verfuegbaren Updates"""
    background_tasks.add_task(_update_all_plugins)
    return {"message": "Updating all plugins", "timestamp": datetime.utcnow().isoformat()}


@app.post("/api/plugins/{component}/update", tags=["Plugins"])
async def update_single_plugin(
    component: str,
    background_tasks: BackgroundTasks,
    user: str = Depends(verify_credentials)
):
    """Aktualisiert ein einzelnes Plugin"""
    background_tasks.add_task(_install_plugin, component)
    return {"message": f"Updating {component}", "timestamp": datetime.utcnow().isoformat()}


@app.delete("/api/plugins/{component}", tags=["Plugins"])
async def uninstall_plugin(
    component: str,
    background_tasks: BackgroundTasks,
    user: str = Depends(verify_credentials)
):
    """Deinstalliert ein Plugin"""
    background_tasks.add_task(_uninstall_plugin, component)
    return {"message": f"Uninstalling {component}", "timestamp": datetime.utcnow().isoformat()}


# =============================================================================
# BACKUP ENDPOINTS
# =============================================================================

@app.get("/api/backups", tags=["Backups"])
async def list_backups(user: str = Depends(verify_credentials)):
    """Listet alle verfuegbaren Backups"""
    backups = []

    if os.path.exists(BACKUP_PATH):
        for item in sorted(os.listdir(BACKUP_PATH), reverse=True):
            item_path = os.path.join(BACKUP_PATH, item)
            if os.path.isdir(item_path):
                info_file = os.path.join(item_path, "backup_info.json")
                if os.path.exists(info_file):
                    try:
                        with open(info_file, 'r') as f:
                            backup_info = json.load(f)
                            backup_info["path"] = item_path
                            backups.append(backup_info)
                    except:
                        pass
                else:
                    # Create basic info from directory
                    stat = os.stat(item_path)
                    backups.append({
                        "filename": item,
                        "created": datetime.fromtimestamp(stat.st_ctime).isoformat(),
                        "size": _format_size(_get_dir_size(item_path)),
                        "type": "unknown",
                        "path": item_path
                    })

    return backups


def _get_dir_size(path: str) -> int:
    """Berechnet Verzeichnisgroesse"""
    total = 0
    try:
        for entry in os.scandir(path):
            if entry.is_file():
                total += entry.stat().st_size
            elif entry.is_dir():
                total += _get_dir_size(entry.path)
    except:
        pass
    return total


def _format_size(size: int) -> str:
    """Formatiert Groesse in lesbare Form"""
    for unit in ['B', 'KB', 'MB', 'GB', 'TB']:
        if size < 1024:
            return f"{size:.1f} {unit}"
        size /= 1024
    return f"{size:.1f} PB"


@app.post("/api/backups/create", tags=["Backups"])
async def create_backup(
    backup_type: str = Query("full", pattern="^(full|quick|db-only)$"),
    background_tasks: BackgroundTasks = None,
    user: str = Depends(verify_credentials)
):
    """Erstellt ein neues Backup"""
    background_tasks.add_task(_create_backup, backup_type)
    return {"message": f"Creating {backup_type} backup", "timestamp": datetime.utcnow().isoformat()}


@app.post("/api/backups/restore/{backup_name}", tags=["Backups"])
async def restore_backup(
    backup_name: str,
    background_tasks: BackgroundTasks,
    user: str = Depends(verify_credentials)
):
    """Stellt Moodle aus einem Backup wieder her"""
    backup_path = os.path.join(BACKUP_PATH, backup_name)
    if not os.path.exists(backup_path):
        raise HTTPException(status_code=404, detail="Backup not found")

    background_tasks.add_task(_restore_backup, backup_name)
    return {"message": f"Restoring from {backup_name}", "timestamp": datetime.utcnow().isoformat()}


@app.delete("/api/backups/{backup_name}", tags=["Backups"])
async def delete_backup(backup_name: str, user: str = Depends(verify_credentials)):
    """Loescht ein Backup"""
    backup_path = os.path.join(BACKUP_PATH, backup_name)
    if not os.path.exists(backup_path):
        raise HTTPException(status_code=404, detail="Backup not found")

    try:
        shutil.rmtree(backup_path)
        logger.info(f"Backup {backup_name} deleted by {user}")
        return {"message": f"Deleted {backup_name}", "timestamp": datetime.utcnow().isoformat()}
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Failed to delete backup: {e}")


@app.get("/api/backups/{backup_name}/download", tags=["Backups"])
async def download_backup(backup_name: str, user: str = Depends(verify_credentials)):
    """Ermoeglicht Download eines Backups"""
    backup_path = os.path.join(BACKUP_PATH, backup_name)
    if not os.path.exists(backup_path):
        raise HTTPException(status_code=404, detail="Backup not found")

    # Check for compressed backup file
    for ext in [".tar.gz", ".zip", ".tar"]:
        archive = backup_path + ext
        if os.path.exists(archive):
            return FileResponse(archive, filename=backup_name + ext)

    raise HTTPException(status_code=404, detail="Backup archive not found")


# =============================================================================
# LOG ENDPOINTS
# =============================================================================

@app.get("/api/logs/{log_type}", tags=["Logs"])
async def get_logs(
    log_type: str,
    lines: int = Query(100, ge=1, le=1000),
    user: str = Depends(verify_credentials)
):
    """Holt Log-Eintraege nach Typ"""
    log_files = {
        "sync": os.path.join(LOG_PATH, "sync.log"),
        "error": os.path.join(LOG_PATH, "error.log"),
        "audit": os.path.join(LOG_PATH, "audit.log"),
        "apache": "/var/log/apache2/error.log",
        "php": "/var/log/php/error.log",
    }

    if log_type not in log_files:
        raise HTTPException(status_code=400, detail=f"Unknown log type. Available: {', '.join(log_files.keys())}")

    return _read_log_file(log_files[log_type], lines)


@app.get("/api/logs/download/{log_type}", tags=["Logs"])
async def download_log(log_type: str, user: str = Depends(verify_credentials)):
    """Ermoeglicht Download einer Log-Datei"""
    log_files = {
        "sync": os.path.join(LOG_PATH, "sync.log"),
        "error": os.path.join(LOG_PATH, "error.log"),
        "audit": os.path.join(LOG_PATH, "audit.log"),
    }

    if log_type not in log_files:
        raise HTTPException(status_code=400, detail="Unknown log type")

    if os.path.exists(log_files[log_type]):
        return FileResponse(log_files[log_type], filename=f"{log_type}.log")

    raise HTTPException(status_code=404, detail="Log file not found")


# =============================================================================
# SETTINGS ENDPOINTS
# =============================================================================

@app.get("/api/settings", tags=["Settings"])
async def get_settings(user: str = Depends(verify_credentials)):
    """Holt aktuelle Konfigurationseinstellungen"""
    return {
        "moodle": {
            "wwwroot": os.getenv("MOODLE_WWWROOT", ""),
            "site_name": os.getenv("MOODLE_SITE_NAME", ""),
            "default_language": os.getenv("MOODLE_DEFAULT_LANGUAGE", "de"),
            "timezone": os.getenv("MOODLE_TIMEZONE", "Europe/Berlin"),
        },
        "sync": {
            "interval": os.getenv("SYNC_INTERVAL", "300"),
            "groups_to_sync": os.getenv("GROUPS_TO_SYNC", ""),
            "sync_all_users": os.getenv("SYNC_ALL_USERS", "0"),
            "soft_delete_enabled": os.getenv("SOFT_DELETE_ENABLED", "1"),
            "delete_enabled": os.getenv("DELETE_ENABLED", "0"),
        },
        "keycloak": {
            "server_url": os.getenv("KEYCLOAK_SERVER_URL", ""),
            "realm": os.getenv("KEYCLOAK_REALM", ""),
            "client_id": os.getenv("KEYCLOAK_CLIENT_ID", ""),
        },
        "updates": {
            "moodle_auto_update": os.getenv("MOODLE_AUTO_UPDATE", "0"),
            "plugin_auto_update": os.getenv("PLUGIN_AUTO_UPDATE", "0"),
        },
        "mail": {
            "smtp_host": os.getenv("SMTP_HOST", ""),
            "smtp_port": os.getenv("SMTP_PORT", "587"),
            "smtp_user": os.getenv("SMTP_USER", ""),
            "smtp_secure": os.getenv("SMTP_SECURE", "tls"),
        }
    }


@app.put("/api/settings/override", tags=["Settings"])
async def update_settings(
    request: SettingsUpdateRequest,
    user: str = Depends(verify_credentials)
):
    """Aktualisiert Override-Einstellungen"""
    override_file = os.path.join(DATA_PATH, "moodle.override.config.json")

    # Bestehende laden
    existing = {}
    if os.path.exists(override_file):
        try:
            with open(override_file, 'r') as f:
                existing = json.load(f)
        except:
            pass

    # Merge
    existing.update(request.settings)

    # Speichern
    with open(override_file, 'w') as f:
        json.dump(existing, f, indent=2)

    logger.info(f"Settings updated by {user}: {list(request.settings.keys())}")
    return {
        "message": "Settings updated",
        "restart_required": True,
        "timestamp": datetime.utcnow().isoformat()
    }


@app.get("/api/settings/override", tags=["Settings"])
async def get_settings_override(user: str = Depends(verify_credentials)):
    """Holt aktuelle Override-Einstellungen"""
    override_file = os.path.join(DATA_PATH, "moodle.override.config.json")

    if os.path.exists(override_file):
        try:
            with open(override_file, 'r') as f:
                return json.load(f)
        except:
            pass

    return {}


# =============================================================================
# MAINTENANCE ENDPOINTS
# =============================================================================

@app.post("/api/maintenance/on", tags=["Maintenance"])
async def maintenance_on(user: str = Depends(verify_credentials)):
    """Aktiviert den Moodle-Wartungsmodus"""
    try:
        result = subprocess.run(
            ["php", f"{MOODLE_PATH}/admin/cli/maintenance.php", "--enable"],
            capture_output=True, text=True, timeout=30
        )
        if result.returncode != 0:
            # Try moosh as fallback
            subprocess.run(
                ["moosh", "-n", "maintenance-on"],
                cwd=MOODLE_PATH, timeout=30
            )
        logger.info(f"Maintenance mode enabled by {user}")
        return {"message": "Maintenance mode enabled", "timestamp": datetime.utcnow().isoformat()}
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Failed to enable maintenance mode: {e}")


@app.post("/api/maintenance/off", tags=["Maintenance"])
async def maintenance_off(user: str = Depends(verify_credentials)):
    """Deaktiviert den Moodle-Wartungsmodus"""
    try:
        result = subprocess.run(
            ["php", f"{MOODLE_PATH}/admin/cli/maintenance.php", "--disable"],
            capture_output=True, text=True, timeout=30
        )
        if result.returncode != 0:
            # Try moosh as fallback
            subprocess.run(
                ["moosh", "-n", "maintenance-off"],
                cwd=MOODLE_PATH, timeout=30
            )
        logger.info(f"Maintenance mode disabled by {user}")
        return {"message": "Maintenance mode disabled", "timestamp": datetime.utcnow().isoformat()}
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Failed to disable maintenance mode: {e}")


@app.get("/api/maintenance/status", tags=["Maintenance"])
async def maintenance_status(user: str = Depends(verify_credentials)):
    """Prueft aktuellen Wartungsmodus-Status"""
    try:
        result = subprocess.run(
            ["php", "-r", f"""
            define('CLI_SCRIPT', true);
            require('{MOODLE_PATH}/config.php');
            echo get_config('core', 'maintenance_enabled') ? 'enabled' : 'disabled';
            """],
            capture_output=True, text=True, timeout=10
        )
        status = result.stdout.strip()
        return {"maintenance_mode": status == "enabled", "timestamp": datetime.utcnow().isoformat()}
    except:
        return {"maintenance_mode": False, "timestamp": datetime.utcnow().isoformat()}


@app.post("/api/cache/clear", tags=["Maintenance"])
async def clear_cache(user: str = Depends(verify_credentials)):
    """Leert alle Moodle-Caches"""
    try:
        subprocess.run(
            ["php", f"{MOODLE_PATH}/admin/cli/purge_caches.php"],
            capture_output=True, timeout=60
        )
        logger.info(f"Cache cleared by {user}")
        return {"message": "Cache cleared", "timestamp": datetime.utcnow().isoformat()}
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Failed to clear cache: {e}")


@app.post("/api/cron/run", tags=["Maintenance"])
async def run_cron(
    background_tasks: BackgroundTasks,
    user: str = Depends(verify_credentials)
):
    """Fuehrt Moodle-Cron manuell aus"""
    background_tasks.add_task(_run_moodle_cron)
    return {"message": "Cron triggered", "timestamp": datetime.utcnow().isoformat()}


@app.post("/api/upgrade/check", tags=["Maintenance"])
async def check_upgrades(user: str = Depends(verify_credentials)):
    """Prueft auf verfuegbare Moodle-Upgrades"""
    try:
        result = subprocess.run(
            ["php", f"{MOODLE_PATH}/admin/cli/upgrade.php", "--non-interactive", "--check-only"],
            capture_output=True, text=True, timeout=60
        )
        upgrade_needed = "Upgrade needed" in result.stdout or result.returncode != 0
        return {
            "upgrade_available": upgrade_needed,
            "message": result.stdout.strip(),
            "timestamp": datetime.utcnow().isoformat()
        }
    except Exception as e:
        return {
            "upgrade_available": False,
            "error": str(e),
            "timestamp": datetime.utcnow().isoformat()
        }


@app.post("/api/upgrade/run", tags=["Maintenance"])
async def run_upgrade(
    background_tasks: BackgroundTasks,
    user: str = Depends(verify_credentials)
):
    """Fuehrt Moodle-Upgrade aus"""
    async def _run_upgrade():
        logger.info(f"Starting Moodle upgrade triggered by {user}")
        try:
            # Enable maintenance mode
            subprocess.run(
                ["php", f"{MOODLE_PATH}/admin/cli/maintenance.php", "--enable"],
                timeout=30
            )
            # Run upgrade
            process = await asyncio.create_subprocess_exec(
                "php", f"{MOODLE_PATH}/admin/cli/upgrade.php", "--non-interactive",
                stdout=asyncio.subprocess.PIPE,
                stderr=asyncio.subprocess.PIPE
            )
            await process.communicate()
            # Disable maintenance mode
            subprocess.run(
                ["php", f"{MOODLE_PATH}/admin/cli/maintenance.php", "--disable"],
                timeout=30
            )
            logger.info("Moodle upgrade completed")
        except Exception as e:
            logger.error(f"Upgrade failed: {e}")

    background_tasks.add_task(_run_upgrade)
    return {"message": "Upgrade started", "timestamp": datetime.utcnow().isoformat()}


# =============================================================================
# STATIC FILES (Frontend)
# =============================================================================

# Mount static files directory
static_path = os.path.join(ADMIN_UI_PATH, "static")
if os.path.exists(static_path):
    app.mount("/static", StaticFiles(directory=static_path), name="static")


@app.get("/", response_class=HTMLResponse, include_in_schema=False)
async def serve_frontend():
    """Serviert das Frontend"""
    index_path = os.path.join(ADMIN_UI_PATH, "index.html")
    if os.path.exists(index_path):
        return FileResponse(index_path)
    return HTMLResponse(content="""
    <html>
        <head><title>Moodle Admin</title></head>
        <body>
            <h1>Moodle Admin API</h1>
            <p>Frontend not found. API documentation available at <a href="/api/docs">/api/docs</a></p>
        </body>
    </html>
    """)


# =============================================================================
# MAIN
# =============================================================================

if __name__ == "__main__":
    import uvicorn

    port = int(os.getenv("ADMIN_UI_PORT", "5000"))
    host = os.getenv("ADMIN_UI_HOST", "0.0.0.0")

    uvicorn.run(
        "admin_api:app",
        host=host,
        port=port,
        reload=os.getenv("DEBUG", "0") == "1",
        log_level="info"
    )
