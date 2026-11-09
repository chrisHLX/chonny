@echo off
rem Starts the MindCollector Ask bridge: answers /ask questions from WoW: Forever. Close this window to stop it.
cd /d "%~dp0"
python ask_bridge.py %*
if errorlevel 1 pause
