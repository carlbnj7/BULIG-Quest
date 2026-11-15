@echo off
setlocal EnableDelayedExpansion
title BULIG Quest - Laragon setup
cd /d "%~dp0"
for %%I in ("%~dp0.") do set "APPDIR=%%~nxI"
set "DB=buligquest"
set "SQL=%~dp0live-update\BULIG-v218-FRESH-INSTALL.sql"

echo.
echo  ==========================================
echo    BULIG Quest - setup for Laragon
echo  ==========================================
echo.
echo  Before you continue: open Laragon and click "Start All".
echo  This makes a NEW, empty "buligquest" database (an older one on this PC is replaced).
echo.
pause

rem ---- find Laragon's MySQL and PHP ----
set "MYSQL="
for /d %%D in ("C:\laragon\bin\mysql\*") do if exist "%%D\bin\mysql.exe" set "MYSQL=%%D\bin\mysql.exe"
set "PHP="
for /d %%D in ("C:\laragon\bin\php\*") do if exist "%%D\php.exe" set "PHP=%%D\php.exe"
if not defined MYSQL (echo  Could not find Laragon's MySQL in C:\laragon\bin\mysql. & goto :fail)
if not defined PHP (echo  Could not find Laragon's PHP in C:\laragon\bin\php. & goto :fail)
if not exist "%SQL%" (echo  Could not find live-update\BULIG-v218-FRESH-INSTALL.sql in this folder. & goto :fail)

rem ---- 1. is MySQL running? ----
"%MYSQL%" -u root -e "SELECT 1" >nul 2>&1
if errorlevel 1 (echo  MySQL is not running. Open Laragon, click "Start All", then run this again. & goto :fail)

rem ---- 2. remove BULIG tables that were loaded into the "mysql" database by mistake ----
echo  [1/5] Cleaning up...
"%MYSQL%" -u root -e "SET FOREIGN_KEY_CHECKS=0; DROP TABLE IF EXISTS mysql.account_id_history, mysql.activities, mysql.activity_completion, mysql.activity_drafts, mysql.admin_messages, mysql.admin_security, mysql.admins, mysql.answers, mysql.app_ratings, mysql.assessment_attempts, mysql.assessment_questions, mysql.assessments, mysql.audit_log, mysql.badges, mysql.bug_report_links, mysql.bug_report_updates, mysql.bug_reports, mysql.bulig_levels, mysql.chat_messages, mysql.chats, mysql.content_issues, mysql.districts, mysql.fluency_log, mysql.grade_levels, mysql.help_requests, mysql.id_sequences, mysql.learning_days, mysql.lessons, mysql.login_attempts, mysql.message_recipients, mysql.message_replies, mysql.modules, mysql.municipalities, mysql.notifications, mysql.pupil_badges, mysql.pupil_cards, mysql.pupil_details, mysql.pupil_group_members, mysql.pupil_groups, mysql.pupil_level_assignments, mysql.pupil_notes, mysql.pupil_progress, mysql.pupil_rewards, mysql.pupil_sections, mysql.pupil_streaks, mysql.pupil_xp, mysql.pupils, mysql.push_queue, mysql.push_subscriptions, mysql.questions, mysql.reading_assessments, mysql.reading_checks, mysql.reading_verifications, mysql.response_history, mysql.rewards, mysql.saved_logins, mysql.schema_migrations, mysql.schools, mysql.sections, mysql.settings, mysql.source_pages, mysql.streak_revives, mysql.studio_lessons, mysql.studio_levels, mysql.studio_pages, mysql.studio_revisions, mysql.studio_testers, mysql.sys_migrations, mysql.teacher_pupils, mysql.teachers, mysql.user_names, mysql.user_roles, mysql.users, mysql.week_plans, mysql.xp_transactions; SET FOREIGN_KEY_CHECKS=1;"

rem ---- 3. the database ----
echo  [2/5] Making the database "%DB%"...
"%MYSQL%" -u root -e "DROP DATABASE IF EXISTS %DB%; CREATE DATABASE %DB% CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if errorlevel 1 goto :fail

echo  [3/5] Loading BULIG Quest (about one minute)...
"%MYSQL%" -u root --default-character-set=utf8mb4 %DB% < "%SQL%"
if errorlevel 1 goto :fail

rem ---- 4. the database login (the Hostinger file is kept as database.hostinger.php) ----
echo  [4/5] Setting the database login...
if not exist "config\database.hostinger.php" copy /y "config\database.php" "config\database.hostinger.php" >nul
(
echo ^<?php
echo return [
echo     'host' =^> '127.0.0.1',
echo     'port' =^> '3306',
echo     'name' =^> '%DB%',
echo     'user' =^> 'root',
echo     'pass' =^> '',
echo     'timezone' =^> 'Asia/Manila',
echo ];
) > "config\database.php"

rem ---- 5. the first administrator ----
echo  [5/5] Make your administrator account:
echo.
"%PHP%" tools\create_admin.php
if errorlevel 1 goto :fail

echo.
echo  ==========================================
echo    Done! Write down the Administrator ID above.
echo    Admin sign-in:  http://localhost/%APPDIR%/public/?page=login^&role=admin
echo    Pupils/teachers: http://localhost/%APPDIR%/public/
echo  ==========================================
start "" "http://localhost/%APPDIR%/public/?page=login&role=admin"
pause
exit /b 0

:fail
echo.
echo  Setup stopped. Take a screenshot of this window and send it.
pause
exit /b 1
