' Starts MindCollector Logs with no console window.
' Double-click to open it. The Windows-startup shortcut passes -Minimized so it starts in the tray.
Set shell = CreateObject("WScript.Shell")
dir = CreateObject("Scripting.FileSystemObject").GetParentFolderName(WScript.ScriptFullName)
args = ""
If WScript.Arguments.Count > 0 Then args = " " & WScript.Arguments(0)
shell.Run "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File """ & dir & "\MindCollectorLogs.ps1""" & args, 0, False
