@echo off
rem Copies Android Kotlin sources up to _src so Claude can read them (folder depth limit).
robocopy "%~dp0android\app\src\main\kotlin\ametecs\managedmobile" "%~dp0_src" /E /NFL /NDL /NJH /NJS
echo done > "%~dp0_src\copied.txt"
