# Game ALS — install guide

Game ALS runs on PHP 8.0+ and MySQL 5.7+ / MariaDB 10.3+ (Hostinger shared hosting, XAMPP, or Laragon).

## 1. Upload the files
Upload everything inside the `upload-to-public_html` folder into your site folder
(for Hostinger: `public_html`, or a subfolder like `public_html/gameals`).

## 2. Make the database
1. In hPanel → Databases → MySQL Databases, make a new database and user (on XAMPP/Laragon use phpMyAdmin; user `root`, empty password).
2. Open phpMyAdmin, choose the new database, and import, in this order:
   - `database/01_schema.sql`
   - `database/02_questions_elementary.sql` (Elementary questions)
   - `database/03_questions_jhs.sql` (Junior High School questions)

   Already installed version 1? Import only `02_questions_elementary.sql` and `03_questions_jhs.sql`
   (they replace the questions; accounts and scores stay).

## 3. Connect the app to the database
1. In the `config` folder, copy `config.sample.php` and name the copy `config.php`.
2. Open `config.php` and type your database name, user, and password.
3. Keep `'debug' => false` on the live site.

Never share `config.php`.

## 4. First sign-in
- Username: `admin`
- Password: `ChangeMe123!`

The app asks for a new password right away.

## 5. Set up
1. **CLCs** — add each Community Learning Center with its municipality and district.
2. **Teachers** — add teacher accounts and tick their CLCs. Give each teacher the username and password shown once on screen.
3. **Settings** — type the SDS name for the certificates.
4. Teachers add learners one by one, or upload the AF3 form (.xlsx or .csv), then print the sign-in slips.

## Game rules built in
- 7 Learning Strands, 3 levels each (Easy, Medium, Difficult), 3 activities per level (items 1–25, 26–50, 51–75).
- Strands go in order: LS1 English → LS1 Filipino → LS2 → LS3 → LS4 → LS5 → LS6. The next strand stays locked until every activity of the strand before it is passed with 75% or more.
- 75% passes an activity. Levels 2 and 3 open once all of Level 1 is passed.
- 3 lives: a wrong answer takes one. 5 correct in a row gives one back (up to 5). No lives left = the activity starts over.
- 3 hints per activity (each takes away two wrong choices); hints do not carry over.
- A Certificate of Passing for each level; a Certificate of Readiness after all 7 strands.
- Leaderboards: Admin by municipality, district, and CLC; Teacher for their CLCs; Learner for their grade level.
- Reports: mastered and least mastered per learner, plus a consolidated CSV per CLC and grade level.

## Updating questions
Admin → Questions. Edit the question, choices, correct answer, or explanation, or hide a question.
Elementary learners get the Elementary questions; Junior High learners get the JHS questions.
