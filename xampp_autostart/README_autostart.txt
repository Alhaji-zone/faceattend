HOW TO AUTO-START PYTHON WITH XAMPP
=====================================

OPTION 1 — Run start_python.bat manually (easiest)
-----------------------------------------------------
Simply double-click start_python.bat in the faceattend root folder
every time you open XAMPP. It automatically installs packages and starts.

OPTION 2 — Windows Task Scheduler (auto-start with PC boot)
-------------------------------------------------------------
1. Press Win+R, type "taskschd.msc", press Enter
2. Click "Create Basic Task" (right panel)
3. Name: "FaceAttend Python Server"
4. Trigger: "When the computer starts"
5. Action: "Start a program"
6. Program/script: C:\xampp\htdocs\faceattend\xampp_autostart\silent_start.vbs
7. In Properties > "Run whether user is logged on or not"
8. Click OK — Python now starts automatically with Windows

OPTION 3 — Add to XAMPP startup via custom Apache conf
--------------------------------------------------------
Add to: C:\xampp\apache\conf\httpd.conf (at the very bottom):
  # This runs on Apache start — not standard but functional:
  # Use the Task Scheduler method above instead for reliability.

OPTION 4 — Use the XAMPP batch approach
-----------------------------------------
Replace C:\xampp\xampp_start.exe calls with a custom launcher.
The silent_start.vbs in this folder does this automatically.
