import subprocess
import time
import json
import urllib.request
import urllib.parse
import os

CHROME_PATH = r"C:\Program Files\Google\Chrome\Application\chrome.exe"
SCREENSHOT_DIR = os.path.abspath('public/screenshots_doc')
os.makedirs(SCREENSHOT_DIR, exist_ok=True)

# Test single screenshot with chrome cli
test_out = os.path.join(SCREENSHOT_DIR, "test_shot.png")
cmd = [
    CHROME_PATH,
    "--headless=new",
    "--disable-gpu",
    "--window-size=1920,1080",
    f"--screenshot={test_out}",
    "http://127.0.0.1:8000/login"
]

print("Running command:", " ".join(cmd))
res = subprocess.run(cmd, capture_output=True, text=True, timeout=15)
print("Return code:", res.returncode)
print("File exists?", os.path.exists(test_out), "Size:", os.path.getsize(test_out) if os.path.exists(test_out) else 0)
