[Setup]
AppName=onelink-billing
AppVersion=1.0
DefaultDirName={pf}\onelink-billing
DefaultGroupName=onelink-billing
OutputBaseFilename=onelink-setup
Compression=lzma
SolidCompression=yes
PrivilegesRequired=admin
WizardStyle=modern
Uninstallable=yes

[Files]
; Copy all files in the current folder (project root)
Source: "*"; DestDir: "{app}"; Flags: recursesubdirs

[Tasks]
Name: "desktopicon"; Description: "Create a &desktop shortcut"; GroupDescription: "Additional icons:"; Flags: unchecked
Name: "installservice"; Description: "Install NSSM service to run boot.bat"; GroupDescription: "Optional actions:"; Flags: unchecked
Name: "runstack"; Description: "Run PHP stack now"; GroupDescription: "Optional actions:"; Flags: unchecked

[Run]
; Run PHP stack install/build silently
Filename: "{app}\php\php.exe"; Parameters: "stack install"; WorkingDir: "{app}"; Flags: waituntilterminated runhidden
Filename: "{app}\php\php.exe"; Parameters: "stack build"; WorkingDir: "{app}"; Flags: waituntilterminated runhidden

; Conditional: Install NSSM service if user checked the box
Filename: "{app}\nssm.exe"; Parameters: "install onelink-billing ""{app}\boot.bat"""; WorkingDir: "{app}"; Tasks: installservice; Flags: waituntilterminated runhidden
Filename: "{app}\nssm.exe"; Parameters: "start onelink-billing"; WorkingDir: "{app}"; Tasks: installservice; Flags: waituntilterminated runhidden

; Conditional: Run PHP stack immediately if user checked the box
Filename: "{app}\php\php.exe"; Parameters: "stack start"; WorkingDir: "{app}"; Tasks: runstack; Flags: nowait postinstall runhidden

[Icons]
; Conditional desktop shortcut that opens browser at local URL
Name: "{commondesktop}\onelink-billing"; Filename: "explorer.exe"; Parameters: "http://127.0.0.1:8000"; IconFilename: "{app}\wifi.png"; Tasks: desktopicon
