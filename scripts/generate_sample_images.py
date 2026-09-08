import os
from PIL import Image, ImageDraw, ImageFont

os.makedirs('public/images', exist_ok=True)

samples = [
    ('public/images/sample_capture_1.jpg', 'ACCESS GRANTED: MFA (RFID+FP+PIN)', 'Ben Famaranglas [ADMIN-001]', '#00ffcc', (10, 25, 45)),
    ('public/images/sample_capture_2.jpg', 'ACCESS GRANTED: RFID CARD', 'Alexander Vance [ENG-2026-089]', '#38bdf8', (20, 35, 55)),
    ('public/images/sample_capture_3.jpg', 'SECURITY ALERT: ACCESS DENIED', 'Unrecognized RFID Card Attempt', '#f43f5e', (50, 15, 25)),
    ('public/images/sample_capture_4.jpg', 'ACCESS GRANTED: BIOMETRIC FP', 'Elena Reyes [STU-2026-104]', '#a855f7', (30, 20, 50)),
]

for filename, title, subtitle, accent, bg in samples:
    img = Image.new('RGB', (640, 480), color=bg)
    draw = ImageDraw.Draw(img)
    
    # Draw high-tech grid lines
    for x in range(0, 640, 40):
        draw.line([(x, 0), (x, 480)], fill=(bg[0]+15, bg[1]+15, bg[2]+15), width=1)
    for y in range(0, 480, 40):
        draw.line([(0, y), (640, y)], fill=(bg[0]+15, bg[1]+15, bg[2]+15), width=1)
        
    # Draw viewfinder corners
    c_len = 30
    draw.line([(30, 30), (30 + c_len, 30)], fill=accent, width=3)
    draw.line([(30, 30), (30, 30 + c_len)], fill=accent, width=3)
    draw.line([(610, 30), (610 - c_len, 30)], fill=accent, width=3)
    draw.line([(610, 30), (610, 30 + c_len)], fill=accent, width=3)
    draw.line([(30, 450), (30 + c_len, 450)], fill=accent, width=3)
    draw.line([(30, 450), (30, 450 - c_len)], fill=accent, width=3)
    draw.line([(610, 450), (610 - c_len, 450)], fill=accent, width=3)
    draw.line([(610, 450), (610, 450 - c_len)], fill=accent, width=3)
    
    # Center target circle
    draw.ellipse([(300, 220), (340, 260)], outline=accent, width=2)
    draw.line([(320, 205), (320, 275)], fill=accent, width=1)
    draw.line([(285, 240), (355, 240)], fill=accent, width=1)
    
    # Top overlay bar
    draw.rectangle([(0, 0), (640, 35)], fill=(0, 0, 0, 180))
    draw.text((20, 10), "TRILOCK ESP32-CAM AI RECOGNITION UNIT #02", fill="#ffffff")
    draw.text((490, 10), "640x480 | 30 FPS", fill="#94a3b8")
    
    # Bottom info banner
    draw.rectangle([(0, 410), (640, 480)], fill=(5, 10, 20, 220))
    draw.text((25, 420), f"[STATUS]: {title}", fill=accent)
    draw.text((25, 445), f"[IDENTITY]: {subtitle}", fill="#e2e8f0")
    draw.text((500, 445), "REC ● LIVE", fill="#22c55e" if "GRANTED" in title else "#ef4444")
    
    img.save(filename, 'JPEG', quality=90)
    print(f"Generated: {filename}")
