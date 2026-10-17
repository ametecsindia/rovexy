; ROVEXY on-premises installer for Windows (Inno Setup 6.3 or newer). build-client.bat compiles it:
;   ISCC.exe /DAppVersion=<version> /DStage=<build\setup-stage> /O<dist> deploy\windows\rovexy-setup.iss
;
; ONE setup.exe for every client. Nothing client-specific is inside: no licence, .env, app key, password or signing key.
; The wizard asks for this server's internet name and certificate, the client's MySQL, and the first administrator, then
; deploy\windows\configure.php (run with the bundled PHP) writes the settings, sets up the database with the app's own
; `rovexy:setup`, registers the Windows services and checks that ROVEXY answers. The client's .lic file is picked up
; next to setup.exe or chosen in the wizard, and is checked and activated only by the app (`rovexy:licence import`).
;
; Run again = upgrade / repair: the settings pages are skipped, the database is backed up first, data is never removed.

#ifndef AppVersion
  #define AppVersion "0.0"
#endif
#ifndef Stage
  #define Stage "..\..\build\setup-stage"
#endif

[Setup]
AppId={{8C1D3B52-6F0E-4C47-9B8A-2F5D7E9A41C3}
AppName=ROVEXY
AppVersion={#AppVersion}
AppVerName=ROVEXY {#AppVersion}
AppPublisher=Ametecs India
AppPublisherURL=https://rovexy.app
VersionInfoVersion={#AppVersion}
DefaultDirName={sd}\ROVEXY
DisableProgramGroupPage=yes
DisableDirPage=auto
UsePreviousAppDir=yes
; services, firewall and C:\ROVEXY need administrator rights; nothing else is elevated
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
MinVersion=10.0
OutputBaseFilename=ROVEXY-Setup-{#AppVersion}
SetupIconFile={#Stage}\rovexy.ico
UninstallDisplayIcon={app}\rovexy.ico
Compression=lzma2/max
SolidCompression=yes
WizardStyle=modern
WizardSizePercent=110,120
CloseApplications=no
SetupLogging=yes

[Files]
Source: "{#Stage}\rovexy\*"; DestDir: "{app}\rovexy"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "{#Stage}\php\*"; DestDir: "{app}\php"; Excludes: "php.ini"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "{#Stage}\caddy\caddy.exe"; DestDir: "{app}\caddy"; Flags: ignoreversion
Source: "{#Stage}\winsw\WinSW.exe"; DestDir: "{app}\winsw"; Flags: ignoreversion
Source: "{#Stage}\installer\*"; DestDir: "{app}\installer"; Flags: ignoreversion
Source: "{#Stage}\activate-licence.bat"; DestDir: "{app}"; Flags: ignoreversion
Source: "{#Stage}\INSTALL.md"; DestDir: "{app}"; Flags: ignoreversion
Source: "{#Stage}\rovexy.ico"; DestDir: "{app}"; Flags: ignoreversion
; used by the wizard before anything is installed (PHP for the checks, Visual C++ runtime that PHP needs)
Source: "{#Stage}\probe\*"; DestDir: "{tmp}\probe"; Flags: dontcopy
Source: "{#Stage}\vc_redist.x64.exe"; DestDir: "{tmp}"; Flags: dontcopy

[Dirs]
Name: "{app}\logs"; Flags: uninsneveruninstall
Name: "{app}\data"; Flags: uninsneveruninstall
Name: "{app}\backups"; Flags: uninsneveruninstall

[InstallDelete]
; upgrade: remove old program code so files deleted in the new version do not linger (never .env, storage, data, backups)
Type: filesandordirs; Name: "{app}\rovexy\app"
Type: filesandordirs; Name: "{app}\rovexy\config"
Type: filesandordirs; Name: "{app}\rovexy\routes"
Type: filesandordirs; Name: "{app}\rovexy\resources"
Type: filesandordirs; Name: "{app}\rovexy\vendor"
Type: filesandordirs; Name: "{app}\rovexy\updater"
Type: filesandordirs; Name: "{app}\rovexy\database\migrations"
Type: filesandordirs; Name: "{app}\rovexy\public\admin"
Type: files; Name: "{app}\rovexy\bootstrap\cache\*.php"
Type: filesandordirs; Name: "{app}\php"

[INI]
Filename: "{app}\ROVEXY Console.url"; Section: "InternetShortcut"; Key: "URL"; String: "{code:ConsoleUrl|}"
Filename: "{app}\ROVEXY Licence.url"; Section: "InternetShortcut"; Key: "URL"; String: "{code:ConsoleUrl|/licence}"

[Icons]
Name: "{autodesktop}\ROVEXY Console"; Filename: "{app}\ROVEXY Console.url"; IconFilename: "{app}\rovexy.ico"
Name: "{autoprograms}\ROVEXY\ROVEXY Console"; Filename: "{app}\ROVEXY Console.url"; IconFilename: "{app}\rovexy.ico"
Name: "{autoprograms}\ROVEXY\Plan and licence"; Filename: "{app}\ROVEXY Licence.url"; IconFilename: "{app}\rovexy.ico"
Name: "{autoprograms}\ROVEXY\Logs"; Filename: "{app}\logs"
Name: "{autoprograms}\ROVEXY\Uninstall ROVEXY"; Filename: "{uninstallexe}"

[Run]
Filename: "{code:ConsoleUrl|}"; Description: "Open the ROVEXY console"; Flags: postinstall shellexec nowait skipifsilent; Check: InstallOk

[UninstallRun]
; stops and removes the services and the firewall rule; rovexy\.env, storage, data, backups and the database are kept
Filename: "{app}\php\php.exe"; Parameters: """{app}\installer\configure.php"" uninstall ""{app}"""; Flags: runhidden; RunOnceId: "RovexyServices"

[UninstallDelete]
Type: files; Name: "{app}\*.url"
Type: filesandordirs; Name: "{app}\services"

[Code]
const
  SERVICES = 'ROVEXY-Web,ROVEXY-PHP,ROVEXY-Scheduler,ROVEXY-Queue';

var
  TlsPage: TInputOptionWizardPage;
  ServerPage: TInputQueryWizardPage;
  CertPage: TInputFileWizardPage;
  CertPass: TPasswordEdit;
  DbPage: TInputQueryWizardPage;
  AdminPage: TInputQueryWizardPage;
  LicPage: TInputFileWizardPage;
  ResultPage: TOutputMsgMemoWizardPage;
  LicButton: TNewButton;
  Busy: TOutputProgressWizardPage;
  ProbeReady, Upgrade: Boolean;
  InstallCode: Integer;

{ third https choice: no domain name, ROVEXY only on this computer at https://localhost:8443 (see configure.php LOCAL_URL) }
function IsLocal: Boolean;
begin
  Result := TlsPage.SelectedValueIndex = 2;
end;

function AppDir: String;
begin
  Result := RemoveBackslash(WizardDirValue);
end;

function IsUpgrade: Boolean;
begin
  Result := FileExists(AppDir + '\rovexy\.env');
end;

function InstallOk: Boolean;
begin
  Result := InstallCode = 0;
end;

function Q(const S: String): String;
begin
  Result := '"' + S + '"';
end;

procedure Add(var A: TArrayOfString; const S: String);
begin
  SetArrayLength(A, GetArrayLength(A) + 1);
  A[GetArrayLength(A) - 1] := S;
end;

{ https://<name>/admin<Suffix>, from the wizard (new install) or from rovexy\.env (upgrade) }
function ConsoleUrl(Suffix: String): String;
var
  Lines: TArrayOfString;
  I: Integer;
  S: String;
begin
  Result := '';
  if Upgrade then begin
    if LoadStringsFromFile(AppDir + '\rovexy\.env', Lines) then
      for I := 0 to GetArrayLength(Lines) - 1 do
        if Pos('APP_URL=', Lines[I]) = 1 then begin
          S := Trim(Copy(Lines[I], 9, Length(Lines[I])));
          if Pos(' ', S) > 0 then S := Copy(S, 1, Pos(' ', S) - 1);
          StringChangeEx(S, '"', '', True);
          Result := S;
        end;
  end
  else if IsLocal then
    Result := 'https://localhost:8443'
  else
    Result := 'https://' + Lowercase(Trim(ServerPage.Values[0]));
  if Copy(Result, Length(Result), 1) = '/' then Result := Copy(Result, 1, Length(Result) - 1);
  Result := Result + '/admin' + Suffix;
end;

{ ── checks before anything is installed ─────────────────────────────────────────────────────────────────── }

function EnsureProbe: Boolean;
var
  Rc: Integer;
begin
  Result := ProbeReady;
  if Result then Exit;
  Busy.SetText('Installing the Microsoft Visual C++ runtime (needed by PHP)...', '');
  Busy.Show;
  try
    ExtractTemporaryFile('vc_redist.x64.exe');
    { 0 = done, 1638 = same or newer already there, 3010 = done, restart later }
    if not Exec(ExpandConstant('{tmp}\vc_redist.x64.exe'), '/install /quiet /norestart', '', SW_HIDE, ewWaitUntilTerminated, Rc)
       or ((Rc <> 0) and (Rc <> 1638) and (Rc <> 3010)) then begin
      SuppressibleMsgBox('The Microsoft Visual C++ runtime could not be installed (code ' + IntToStr(Rc) + ').'#13#10
        + 'Install "Microsoft Visual C++ Redistributable 2015-2022 (x64)" and run setup again.', mbCriticalError, MB_OK, IDOK);
      Exit;
    end;
    ExtractTemporaryFiles('{tmp}\probe\*');
    ProbeReady := True;
    Result := True;
  finally
    Busy.Hide;
  end;
end;

{ Runs configure.php check for one wizard page. Errors keep the page open; warnings ask to continue. }
function Check(const Part: String; Job: TArrayOfString): Boolean;
var
  JobFile, Errors, Warns: String;
  Lines: TArrayOfString;
  I, Rc: Integer;
begin
  Result := False;
  if not EnsureProbe then Exit;
  JobFile := ExpandConstant('{tmp}\check.job');
  Add(Job, 'part=' + Part);
  Add(Job, 'app=' + AppDir);
  DeleteFile(JobFile + '.out');
  SaveStringsToUTF8File(JobFile, Job, False);
  Busy.SetText('Checking...', '');
  Busy.Show;
  try
    Exec(ExpandConstant('{tmp}\probe\php.exe'),
      '-n -d extension_dir=' + Q(ExpandConstant('{tmp}\probe')) + ' -d extension=openssl -d extension=pdo_mysql -d extension=mbstring '
      + Q(ExpandConstant('{tmp}\probe\configure.php')) + ' check ' + Q(JobFile), '', SW_HIDE, ewWaitUntilTerminated, Rc);
  finally
    Busy.Hide;
  end;
  DeleteFile(JobFile);   { holds passwords; configure.php already deleted it - this is a second guard }
  if not LoadStringsFromFile(JobFile + '.out', Lines) then begin
    SuppressibleMsgBox('The check could not run (code ' + IntToStr(Rc) + '). Is something blocking programs in the temp folder?',
      mbCriticalError, MB_OK, IDOK);
    Exit;
  end;
  Errors := '';
  Warns := '';
  for I := 0 to GetArrayLength(Lines) - 1 do
    if Pos('ERROR: ', Lines[I]) = 1 then Errors := Errors + '- ' + Copy(Lines[I], 8, Length(Lines[I])) + #13#10#13#10
    else if Pos('WARN: ', Lines[I]) = 1 then Warns := Warns + '- ' + Copy(Lines[I], 7, Length(Lines[I])) + #13#10#13#10;
  if Errors <> '' then
    SuppressibleMsgBox(Trim(Errors), mbError, MB_OK, IDOK)
  else
    Result := (Warns = '') or (SuppressibleMsgBox(Warns + 'Continue anyway?', mbConfirmation, MB_YESNO, IDNO) = IDYES);
end;

function ServerJob: TArrayOfString;
begin
  if IsLocal then Add(Result, 'domain=localhost') else Add(Result, 'domain=' + Lowercase(Trim(ServerPage.Values[0])));
  Add(Result, 'email=' + Trim(ServerPage.Values[1]));
  case TlsPage.SelectedValueIndex of
    0: Add(Result, 'tls=le');
    1: Add(Result, 'tls=file');
  else
    Add(Result, 'tls=local');
  end;
  Add(Result, 'cert=' + Trim(CertPage.Values[0]));
  Add(Result, 'key=' + Trim(CertPage.Values[1]));
  Add(Result, 'certpass=' + CertPass.Text);
end;

function DbJob: TArrayOfString;
begin
  Add(Result, 'dbhost=' + Trim(DbPage.Values[0]));
  Add(Result, 'dbport=' + Trim(DbPage.Values[1]));
  Add(Result, 'dbname=' + Trim(DbPage.Values[2]));
  Add(Result, 'dbuser=' + Trim(DbPage.Values[3]));
  Add(Result, 'dbpass=' + DbPage.Values[4]);
end;

{ ── wizard ───────────────────────────────────────────────────────────────────────────────────────────────── }

function InitializeSetup: Boolean;
var
  Release: Cardinal;
begin
  { WinSW, which runs ROVEXY as Windows services, needs .NET Framework 4.6.1 or newer (Windows 10 / Server 2016 have it) }
  Release := 0;
  if not RegQueryDWordValue(HKLM64, 'SOFTWARE\Microsoft\NET Framework Setup\NDP\v4\Full', 'Release', Release) then
    if not RegQueryDWordValue(HKLM32, 'SOFTWARE\Microsoft\NET Framework Setup\NDP\v4\Full', 'Release', Release) then Release := 0;
  Result := Release >= 394254;
  if not Result then
    SuppressibleMsgBox('ROVEXY needs .NET Framework 4.6.1 or newer. Install it (Windows Update, or Server Manager - Add features) '
      + 'and run setup again.', mbCriticalError, MB_OK, IDOK);
end;

procedure LicButtonClick(Sender: TObject); forward;

procedure InitializeWizard;
var
  F: TFindRec;
  Found: String;
  Count: Integer;
begin
  Busy := CreateOutputProgressPage('Please wait', '');

  TlsPage := CreateInputOptionPage(wpSelectDir, 'Secure connection (https)',
    'Phones and browsers reach ROVEXY over https, so this server needs a certificate.',
    'The ROVEXY phone app only accepts a certificate from a public certificate authority, for the server''s internet name. How should this server get one?',
    True, False);
  TlsPage.Add('Let''s Encrypt - free and automatic (the name must point to this server, ports 80 and 443 open from the internet)');
  TlsPage.Add('Our own certificate file (.pfx, or .crt / .pem with its .key)');
  TlsPage.Add('No domain name - only on this computer (https://localhost:8443). The phone app cannot connect in this mode.');
  TlsPage.SelectedValueIndex := 0;

  ServerPage := CreateInputQueryPage(TlsPage.ID, 'Server name',
    'The https address staff and phones use for this server.',
    'Internet name of this server - its DNS record must point to this server. Example: rovexy.yourcompany.com');
  ServerPage.Add('Server name (no https://):', False);
  ServerPage.Add('E-mail for certificate notices (Let''s Encrypt, optional):', False);

  CertPage := CreateInputFilePage(ServerPage.ID, 'Certificate file',
    'The certificate for the server name, from your certificate provider.',
    'Use the "full chain" file if you have one. The file is converted and kept in the install folder, readable by Administrators only.');
  CertPage.Add('Certificate (.pfx / .p12, or .crt / .pem):', 'Certificates|*.pfx;*.p12;*.crt;*.pem;*.cer|All files|*.*', '.pfx');
  CertPage.Add('Private key (.key - only with a .crt / .pem file):', 'Private key|*.key;*.pem|All files|*.*', '.key');
  with TNewStaticText.Create(CertPage) do begin
    Parent := CertPage.Surface;
    Caption := 'Password of the .pfx or of the key (leave empty if none):';
    Top := CertPage.Edits[1].Top + CertPage.Edits[1].Height + ScaleY(16);
  end;
  CertPass := TPasswordEdit.Create(CertPage);
  CertPass.Parent := CertPage.Surface;
  CertPass.Top := CertPage.Edits[1].Top + CertPage.Edits[1].Height + ScaleY(34);
  CertPass.Width := CertPage.Edits[1].Width;

  DbPage := CreateInputQueryPage(CertPage.ID, 'Database',
    'ROVEXY keeps its data in your MySQL or MariaDB server.',
    'A MySQL user with all rights on this database. Setup creates the database if it is missing; a database that already '
    + 'holds other data is never used or changed.');
  DbPage.Add('MySQL server:', False);
  DbPage.Add('Port:', False);
  DbPage.Add('Database name:', False);
  DbPage.Add('User:', False);
  DbPage.Add('Password:', True);
  DbPage.Values[0] := '127.0.0.1';
  DbPage.Values[1] := '3306';
  DbPage.Values[2] := 'rovexy';
  DbPage.Values[3] := 'rovexy';

  AdminPage := CreateInputQueryPage(DbPage.ID, 'Company and administrator',
    'The company that uses this server and its first Company Administrator.',
    'The administrator signs in to the ROVEXY console with this e-mail and password and then adds managers and staff.');
  AdminPage.Add('Company name:', False);
  AdminPage.Add('Administrator e-mail:', False);
  AdminPage.Add('Password (12 or more characters):', True);
  AdminPage.Add('Password again:', True);

  LicPage := CreateInputFilePage(AdminPage.ID, 'Licence',
    'The licence file (.lic) Ametecs made for this company.',
    'A licence file is locked to one server. If yours is not ready yet, leave this empty: ROVEXY then starts in evaluation mode '
    + '(14 days, 10 users), and the last page shows this server''s ID to send to Ametecs. You can add the file then, or later '
    + 'in the console under Plan && licence.');
  LicPage.Add('Licence file (optional):', 'ROVEXY licence (*.lic)|*.lic|All files|*.*', '.lic');
  { a .lic file next to setup.exe is used automatically }
  Count := 0;
  if FindFirst(ExpandConstant('{src}\*.lic'), F) then
    try
      repeat
        Count := Count + 1;
        Found := ExpandConstant('{src}\') + F.Name;
      until not FindNext(F);
    finally
      FindClose(F);
    end;
  if Count = 1 then LicPage.Values[0] := Found;

  ResultPage := CreateOutputMsgMemoPage(wpInfoAfter, 'ROVEXY status',
    'What setup did, and the licence of this server.', '', '');
  LicButton := TNewButton.Create(ResultPage);
  LicButton.Parent := ResultPage.Surface;
  LicButton.Caption := 'Choose licence file...';
  LicButton.Width := ScaleX(170);
  LicButton.Height := ScaleY(25);
  LicButton.Left := 0;
  LicButton.OnClick := @LicButtonClick;
  ResultPage.RichEditViewer.Font.Name := 'Consolas';
end;

function ShouldSkipPage(PageID: Integer): Boolean;
begin
  Upgrade := IsUpgrade;
  Result := Upgrade and ((PageID = TlsPage.ID) or (PageID = ServerPage.ID) or (PageID = CertPage.ID) or (PageID = DbPage.ID)
    or (PageID = AdminPage.ID) or (PageID = LicPage.ID));
  if (PageID = CertPage.ID) and (TlsPage.SelectedValueIndex <> 1) then Result := True;
  if (PageID = ServerPage.ID) and IsLocal then Result := True;
end;

function NextButtonClick(CurPageID: Integer): Boolean;
var
  Email: String;
begin
  Result := True;
  if CurPageID = wpSelectDir then
    Upgrade := IsUpgrade
  else if (CurPageID = TlsPage.ID) and IsLocal then
    Result := Check('server', ServerJob)
  else if CurPageID = ServerPage.ID then
    Result := Check('server', ServerJob)
  else if CurPageID = CertPage.ID then
    Result := Check('cert', ServerJob)
  else if CurPageID = DbPage.ID then
    Result := Check('database', DbJob)
  else if CurPageID = AdminPage.ID then begin
    Email := Trim(AdminPage.Values[1]);
    if Trim(AdminPage.Values[0]) = '' then begin
      SuppressibleMsgBox('Enter the company name.', mbError, MB_OK, IDOK); Result := False;
    end else if (Pos('@', Email) < 2) or (Pos('.', Copy(Email, Pos('@', Email), Length(Email))) = 0) or (Pos(' ', Email) > 0) then begin
      SuppressibleMsgBox('Enter a valid administrator e-mail.', mbError, MB_OK, IDOK); Result := False;
    end else if Length(AdminPage.Values[2]) < 12 then begin
      SuppressibleMsgBox('The password needs 12 or more characters.', mbError, MB_OK, IDOK); Result := False;
    end else if AdminPage.Values[2] <> AdminPage.Values[3] then begin
      SuppressibleMsgBox('The two passwords are not the same.', mbError, MB_OK, IDOK); Result := False;
    end;
  end else if CurPageID = LicPage.ID then begin
    if (Trim(LicPage.Values[0]) <> '') and not FileExists(Trim(LicPage.Values[0])) then begin
      SuppressibleMsgBox('The licence file was not found.', mbError, MB_OK, IDOK); Result := False;
    end;
  end;
end;

function UpdateReadyMemo(Space, NewLine, MemoUserInfoInfo, MemoDirInfo, MemoTypeInfo, MemoComponentsInfo,
  MemoGroupInfo, MemoTasksInfo: String): String;
begin
  Result := MemoDirInfo + NewLine + NewLine;
  if Upgrade then
    Result := Result + 'Upgrade to ROVEXY {#AppVersion}:' + NewLine + Space + 'the database is backed up first, settings and data are kept.'
  else begin
    Result := Result + 'Console:' + NewLine + Space + ConsoleUrl('') + NewLine + NewLine
      + 'Certificate:' + NewLine + Space;
    case TlsPage.SelectedValueIndex of
      0: Result := Result + 'Let''s Encrypt';
      1: Result := Result + Trim(CertPage.Values[0]);
    else
      Result := Result + 'this computer''s own certificate (no domain name)';
    end;
    Result := Result + NewLine + NewLine + 'Database:' + NewLine + Space + Trim(DbPage.Values[2]) + ' on ' + Trim(DbPage.Values[0])
      + ':' + Trim(DbPage.Values[1]) + ' (user ' + Trim(DbPage.Values[3]) + ')' + NewLine + NewLine
      + 'Company and administrator:' + NewLine + Space + Trim(AdminPage.Values[0]) + ', ' + Trim(AdminPage.Values[1]) + NewLine + NewLine
      + 'Licence:' + NewLine + Space;
    if Trim(LicPage.Values[0]) <> '' then Result := Result + Trim(LicPage.Values[0]) else Result := Result + 'none yet (evaluation)';
  end;
  Result := Result + NewLine + NewLine + 'Windows services ROVEXY-Web, ROVEXY-PHP, ROVEXY-Scheduler and ROVEXY-Queue start with Windows.';
end;

{ ── install ──────────────────────────────────────────────────────────────────────────────────────────────── }

procedure StopServices;
var
  Ids: TStringList;
  I, Rc: Integer;
begin
  Ids := TStringList.Create;
  try
    Ids.CommaText := SERVICES;
    for I := 0 to Ids.Count - 1 do
      if FileExists(AppDir + '\services\' + Ids[I] + '.exe') then
        Exec(AppDir + '\services\' + Ids[I] + '.exe', 'stop', '', SW_HIDE, ewWaitUntilTerminated, Rc);
  finally
    Ids.Free;
  end;
end;

procedure StartServices;
var
  Ids: TStringList;
  I, Rc: Integer;
begin
  Ids := TStringList.Create;
  try
    Ids.CommaText := SERVICES;
    for I := Ids.Count - 1 downto 0 do
      if FileExists(AppDir + '\services\' + Ids[I] + '.exe') then
        Exec(AppDir + '\services\' + Ids[I] + '.exe', 'start', '', SW_HIDE, ewWaitUntilTerminated, Rc);
  finally
    Ids.Free;
  end;
end;

{ Upgrade: back up the database and stop the services before any file is replaced. }
function PrepareToInstall(var NeedsRestart: Boolean): String;
var
  Rc: Integer;
  Msg: String;
begin
  Result := '';
  Upgrade := IsUpgrade;
  if not EnsureProbe then begin
    Result := 'The Microsoft Visual C++ runtime could not be installed.';
    Exit;
  end;
  if not Upgrade then Exit;
  StopServices;
  if FileExists(AppDir + '\php\php.exe') then begin
    Busy.SetText('Backing up the database...', '');
    Busy.Show;
    try
      Exec(AppDir + '\php\php.exe', Q(ExpandConstant('{tmp}\probe\configure.php')) + ' backup ' + Q(AppDir), AppDir, SW_HIDE,
        ewWaitUntilTerminated, Rc);
    finally
      Busy.Hide;
    end;
    Msg := '';
    if Rc = 3 then
      Msg := 'mysqldump (MySQL client tools) was not found, so the database cannot be backed up before the upgrade.'
    else if Rc <> 0 then
      Msg := 'The database backup failed (see ' + AppDir + '\logs\install.log).';
    if (Msg <> '') and (SuppressibleMsgBox(Msg + #13#10#13#10 + 'Upgrade without a database backup?', mbConfirmation, MB_YESNO, IDNO) <> IDYES) then begin
      StartServices;
      Result := 'Upgrade cancelled - nothing was changed. Install the MySQL client tools (or set ROVEXY_MYSQLDUMP in rovexy\.env) and run setup again.';
    end;
  end;
end;

procedure CurStepChanged(CurStep: TSetupStep);
var
  Job, Db: TArrayOfString;
  JobFile: String;
  I: Integer;
begin
  if CurStep <> ssPostInstall then Exit;
  JobFile := ExpandConstant('{tmp}\install.job');
  if not Upgrade then begin
    Job := ServerJob;
    Db := DbJob;
    for I := 0 to GetArrayLength(Db) - 1 do Add(Job, Db[I]);
    Add(Job, 'company=' + Trim(AdminPage.Values[0]));
    Add(Job, 'adminemail=' + Trim(AdminPage.Values[1]));
    Add(Job, 'adminpass=' + AdminPage.Values[2]);
    Add(Job, 'licence=' + Trim(LicPage.Values[0]));
    SaveStringsToUTF8File(JobFile, Job, False);
  end;
  WizardForm.StatusLabel.Caption := 'Setting up ROVEXY: database, web server, services and checks. This can take a few minutes...';
  WizardForm.ProgressGauge.Style := npbstMarquee;
  try
    { php.ini first: PHP loads its extensions only at start, so the install step must be a new PHP process }
    Exec(ExpandConstant('{app}\php\php.exe'), '-n ' + Q(ExpandConstant('{app}\installer\configure.php')) + ' phpini '
      + Q(ExpandConstant('{app}')), ExpandConstant('{app}'), SW_HIDE, ewWaitUntilTerminated, InstallCode);
    if not Exec(ExpandConstant('{app}\php\php.exe'), Q(ExpandConstant('{app}\installer\configure.php')) + ' install '
      + Q(ExpandConstant('{app}')) + ' ' + Q(JobFile), ExpandConstant('{app}'), SW_HIDE, ewWaitUntilTerminated, InstallCode) then
      InstallCode := -1;
  finally
    WizardForm.ProgressGauge.Style := npbstNormal;
    DeleteFile(JobFile);
  end;
  Log('configure.php install finished with code ' + IntToStr(InstallCode));
end;

{ ── result page ──────────────────────────────────────────────────────────────────────────────────────────── }

procedure ShowStatus;
var
  S, L: AnsiString;
  Head: String;
begin
  if not LoadStringFromFile(ExpandConstant('{app}\logs\install-result.txt'), S) then S := '';
  if not LoadStringFromFile(ExpandConstant('{app}\logs\licence.txt'), L) then L := '';
  if InstallOk then
    Head := 'ROVEXY is installed.'
  else
    Head := 'SETUP DID NOT FINISH. Fix what is listed below and run setup again (it continues where it stopped). '
      + 'Details: ' + ExpandConstant('{app}\logs\install.log');
  ResultPage.RichEditViewer.Lines.Text := Head + #13#10#13#10 + String(S) + #13#10 + 'LICENCE'#13#10 + String(L);
  LicButton.Enabled := FileExists(ExpandConstant('{app}\rovexy\.env'));
end;

procedure LicButtonClick(Sender: TObject);
var
  F: String;
  Rc: Integer;
begin
  F := '';
  if not GetOpenFileName('Licence file from Ametecs', F, ExpandConstant('{src}'), 'ROVEXY licence (*.lic)|*.lic|All files|*.*', 'lic') then Exit;
  Exec(ExpandConstant('{app}\php\php.exe'), Q(ExpandConstant('{app}\installer\configure.php')) + ' licence ' + Q(ExpandConstant('{app}'))
    + ' ' + Q(F), ExpandConstant('{app}'), SW_HIDE, ewWaitUntilTerminated, Rc);
  ShowStatus;
  WizardForm.ActiveControl := WizardForm.NextButton;
  if Rc = 0 then
    SuppressibleMsgBox('The licence is activated.', mbInformation, MB_OK, IDOK)
  else
    SuppressibleMsgBox('The licence file was not accepted - the reason is shown on this page.', mbError, MB_OK, IDOK);
end;

procedure CurPageChanged(CurPageID: Integer);
begin
  if CurPageID = ResultPage.ID then begin
    { placed here: the page has its final size only once it is shown }
    LicButton.Top := ResultPage.Surface.Height - LicButton.Height;
    ResultPage.RichEditViewer.Height := LicButton.Top - ScaleY(8) - ResultPage.RichEditViewer.Top;
    ShowStatus;
  end;
end;

procedure CurUninstallStepChanged(CurUninstallStep: TUninstallStep);
begin
  if CurUninstallStep = usPostUninstall then
    SuppressibleMsgBox('ROVEXY was removed. Your data was kept: the database, and in ' + ExpandConstant('{app}')
      + ' the settings (rovexy\.env), files (rovexy\storage), certificates (data), backups and logs. '
      + 'Installing ROVEXY again in the same folder continues with them.', mbInformation, MB_OK, IDOK);
end;
