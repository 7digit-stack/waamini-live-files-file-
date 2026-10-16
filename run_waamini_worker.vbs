' WAAMINI background worker - runs AI queue + demo reminders silently (no window)
Set WshShell = CreateObject("WScript.Shell")
WshShell.Run """C:\xampp\php\php.exe"" ""C:\xampp\htdocs\waamini\process_ai_queue.php""", 0, False
