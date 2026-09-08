import os
import subprocess
import time

CHROME_PATH = r"C:\Program Files\Google\Chrome\Application\chrome.exe"
DATA_DIR = os.path.abspath('scratch/chrome_data')
os.makedirs(DATA_DIR, exist_ok=True)
os.makedirs('public/screenshots_doc', exist_ok=True)

# 1. Login session with screenshot
login_out = os.path.abspath('public/screenshots_doc/login_init.png')
login_cmd = [
    CHROME_PATH,
    "--headless=new",
    "--disable-gpu",
    f"--user-data-dir={DATA_DIR}",
    f"--screenshot={login_out}",
    "http://127.0.0.1:8000/dev-login"
]
subprocess.run(login_cmd, capture_output=True, timeout=10)

# 2. Test dashboard screenshot
dash_out = os.path.abspath('public/screenshots_doc/test_dash.png')
dash_cmd = [
    CHROME_PATH,
    "--headless=new",
    "--disable-gpu",
    "--window-size=1920,1080",
    f"--user-data-dir={DATA_DIR}",
    f"--screenshot={dash_out}",
    "http://127.0.0.1:8000/dashboard"
]
res = subprocess.run(dash_cmd, capture_output=True, text=True, timeout=10)
print("Dashboard screenshot exists?", os.path.exists(dash_out), "Size:", os.path.getsize(dash_out) if os.path.exists(dash_out) else 0)
