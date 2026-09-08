import os
import subprocess

CHROME_PATH = r"C:\Program Files\Google\Chrome\Application\chrome.exe"
SCREENSHOT_DIR = os.path.abspath('public/screenshots_doc')
out_path = os.path.join(SCREENSHOT_DIR, "test_fast_dash.png")

cmd = [
    CHROME_PATH,
    "--headless=new",
    "--disable-gpu",
    "--virtual-time-budget=2000",
    "--window-size=1920,1080",
    f"--screenshot={out_path}",
    "http://127.0.0.1:8000/dashboard?doc_auth=1"
]

print("Running fast screenshot with virtual-time-budget...")
res = subprocess.run(cmd, capture_output=True, text=True, timeout=10)
print("Finished. Exists?", os.path.exists(out_path), "Size:", os.path.getsize(out_path) if os.path.exists(out_path) else 0)
