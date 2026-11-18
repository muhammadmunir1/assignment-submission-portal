# Assignment Submission Portal — Demo

Author: Muhammad Munir | Reg No: CIIT/FA22-BCS-071/ATK

Course: DevOps for Cloud Computing (CSC418) · Instructor: Mr. Babar Shazad · COMSATS University Islamabad, Attock Campus · Lab Mid

Run this on `main`. The Pages job deploys only for a push to `main` after the `test` job succeeds. Do the three pushes in order. Leave the receiver and ngrok running the whole time.

## 0. Pre-flight

Confirm each item before the first push.

- `receiver/.env` exists and `REPORT_TOKEN` is the same value as the GitHub secret `REPORT_TOKEN`.
- The receiver is running:

```bash
composer receiver
```

- ngrok is forwarding to port 4000:

```bash
ngrok http --url=<your-static-domain> 4000
```

Older ngrok:

```bash
ngrok http --domain=<your-static-domain> 4000
```

- GitHub Actions secrets are set: `REPORT_URL` (`https://<static-domain>/report`), `REPORT_TOKEN`, `REPORT_EMAIL_TO`, `EMAIL_USERNAME`, `EMAIL_PASSWORD`.
- A browser tab is open on the repository Actions page: `https://github.com/<your-username>/<your-repo>/actions`.
- A terminal in the project folder is ready to show `testreport.json`.

Health check. Windows:

```bat
curl.exe http://localhost:4000/health
```

macOS or Linux:

```bash
curl http://localhost:4000/health
```

Expected body: `{"ok":true}`.

Keep a terminal in the project folder for the report file. If a report has already arrived, show it. Windows:

```bat
type testreport.json
```

macOS or Linux:

```bash
cat testreport.json
```

The dashboard at http://localhost:4000 refreshes every 3 seconds. Use it beside the JSON file.

## 1. Passing run

Add one harmless line at the end of `README.md`:

```text
Demo check: passing run.
```

Commit and push to `main`:

```bash
git add README.md
git commit -m "Passing pipeline check"
git push origin main
```

Watch the `CI` workflow. The `test` job should be green, then the `deploy` job should publish Pages.

On the lab machine, after the Send report to local receiver step succeeds:

```bat
type testreport.json
```

```bash
cat testreport.json
```

Expect `"status": "passed"`, `"failed": 0`, and a `receivedAt` field added by the receiver. A new file also appears under `reports/` and the previous history file is left in place.

Open the email. The subject looks like:

```text
[PASSED] <your-username>/<your-repo> @ <sha7> - 20/20 tests passed
```

The banner is green and reads PASSED. The Pages URL is the `page_url` output of Deploy to GitHub Pages, usually `https://<your-username>.github.io/<your-repo>/`.

Screenshots:

- The green Actions run, with the `test` job and the `deploy` job.
- `testreport.json` showing `"status": "passed"`.
- The passing email, including the subject and the PASSED banner.
- The Pages site loaded at its public URL.

## 2. Introduce the defect

In `src/Validator.php`, comment out only the registration-number check. Leave the format check below it.

Before:

```php
        // DEMO SWITCH: this is the only place a missing registration number is rejected.
        // Comment out this if-block to accept a blank regNo during the demo.
        if ($regNo === '') {
            $errors['regNo'] = 'Registration number is required';
        }
```

After:

```php
        // DEMO SWITCH: this is the only place a missing registration number is rejected.
        // Comment out this if-block to accept a blank regNo during the demo.
        // if ($regNo === '') {
        //     $errors['regNo'] = 'Registration number is required';
        // }
```

Commit and push:

```bash
git add src/Validator.php
git commit -m "Introduce defect"
git push origin main
```

The report, upload, receiver, and email steps still run. Fail the job when tests failed then exits 1, so the `test` job is red. The `deploy` job is skipped because it `needs: test`. The Pages site stays on the previous publish.

Three tests fail. The one this defect is aimed at:

- `App\Tests\SubmissionTest::test_missing_registration_number_is_rejected_with_validation_message`
- Message: `A submission without a registration number should be rejected.`
- Assertion: `Failed asserting that true is false.`

The same blank registration number also fails:

- `App\Tests\SubmissionTest::test_whitespace_only_values_are_rejected` with `Whitespace is not a registration number.`
- `App\Tests\ApiTest::test_invalid_post_returns_422_with_field_errors` with `Invalid input should return 422.` and `Failed asserting that 201 is identical to 422.`

Show the updated file. Windows:

```bat
type testreport.json
```

macOS or Linux:

```bash
cat testreport.json
```

Expect `"status": "failed"`, `"failed": 3`, and a `failureMessage` on those three tests. The receiver writes a second history file under `reports/` and does not delete the passing one.

The email subject looks like:

```text
[FAILED] <your-username>/<your-repo> @ <sha7> - 3/20 tests failed
```

The banner is red and reads FAILED. Reload the Pages URL and confirm it is still the previous site.

Screenshots:

- The red Actions run, with deploy skipped.
- The log of `test_missing_registration_number_is_rejected_with_validation_message`, including the assertion message.
- `testreport.json` showing `"status": "failed"` and a `failureMessage`.
- The failing email, including the subject and the FAILED banner.
- The Pages URL, unchanged from stage 1.

## 3. Fix

Restore the check so `src/Validator.php` matches the before block in stage 2 again:

```php
        // DEMO SWITCH: this is the only place a missing registration number is rejected.
        // Comment out this if-block to accept a blank regNo during the demo.
        if ($regNo === '') {
            $errors['regNo'] = 'Registration number is required';
        }
```

Commit and push:

```bash
git add src/Validator.php
git commit -m "Fix defect"
git push origin main
```

The `test` job is green and the `deploy` job runs again.

```bat
type testreport.json
```

```bash
cat testreport.json
```

Expect `"status": "passed"` and `"failed": 0`. The email subject is back to `20/20 tests passed` with a green PASSED banner. The Pages site updates after the `deploy` job finishes.

Screenshots:

- The green Actions run, including the `deploy` job.
- `testreport.json` showing `"status": "passed"`.
- The corrected email.
- The Pages URL after this deploy.
