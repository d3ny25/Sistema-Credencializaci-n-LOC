@echo off
rem Compila el agente de estación en x86 (requisito del EDSDK de Canon y del SDK de Cross Match).
rem Solo usa el compilador de .NET Framework 4.8 incluido en Windows; no descarga ni instala nada.
setlocal
set CSC=%WINDIR%\Microsoft.NET\Framework\v4.0.30319\csc.exe
set WPF=%WINDIR%\Microsoft.NET\Framework\v4.0.30319\WPF
if not exist "%CSC%" (
  echo No se encontro %CSC%
  exit /b 1
)
if not exist "%~dp0bin" mkdir "%~dp0bin"
"%CSC%" /nologo /platform:x86 /target:exe /optimize+ /out:"%~dp0bin\AgenteEstacion.exe" ^
  /r:System.Web.Extensions.dll /r:System.Drawing.dll /r:System.Management.dll ^
  /r:"%WPF%\System.Printing.dll" /r:"%WPF%\ReachFramework.dll" /r:"%WPF%\WindowsBase.dll" ^
  "%~dp0src\Program.cs" "%~dp0src\Ticket.cs" "%~dp0src\Camara.cs" "%~dp0src\CamaraEdsdk.cs" "%~dp0src\Lector.cs" "%~dp0src\Impresion.cs"
exit /b %ERRORLEVEL%
