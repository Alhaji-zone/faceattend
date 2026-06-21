' FaceAttend Silent Python Launcher
' Add this to Task Scheduler (trigger: At startup) for automatic launch.
Dim objShell
Set objShell = WScript.CreateObject("WScript.Shell")
Dim scriptPath
scriptPath = Left(WScript.ScriptFullName, InStrRev(WScript.ScriptFullName, "\"))
objShell.Run "cmd /c """ & scriptPath & "..\start_python.bat""", 0, False
Set objShell = Nothing
