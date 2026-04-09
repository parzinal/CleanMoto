@echo off
echo Installing Composer dependencies...
echo.

REM Try different possible PHP paths in Laragon
if exist "C:\laragon\bin\php\php.exe" (
    set PHP_PATH=C:\laragon\bin\php\php.exe
) else if exist "C:\laragon\bin\php\php-8.2.12-Win32-vs16-x64\php.exe" (
    set PHP_PATH=C:\laragon\bin\php\php-8.2.12-Win32-vs16-x64\php.exe
) else if exist "C:\laragon\bin\php\php-8.1.10-Win32-vs16-x64\php.exe" (
    set PHP_PATH=C:\laragon\bin\php\php-8.1.10-Win32-vs16-x64\php.exe
) else if exist "C:\laragon\bin\php\php-8.0.30-Win32-vs16-x64\php.exe" (
    set PHP_PATH=C:\laragon\bin\php\php-8.0.30-Win32-vs16-x64\php.exe
) else (
    echo Error: PHP not found in Laragon directory
    echo Please manually run: C:\laragon\bin\php\[your-php-version]\php.exe C:\laragon\bin\composer\composer.phar install
    pause
    exit /b 1
)

REM Check for composer
if exist "C:\laragon\bin\composer\composer.phar" (
    set COMPOSER_PATH=C:\laragon\bin\composer\composer.phar
) else (
    echo Error: Composer not found
    pause
    exit /b 1
)

echo Using PHP: %PHP_PATH%
echo Using Composer: %COMPOSER_PATH%
echo.

REM Run composer install
"%PHP_PATH%" "%COMPOSER_PATH%" install

echo.
echo Installation complete!
echo.
pause
