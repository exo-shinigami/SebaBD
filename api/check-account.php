<?php
/**
 * SebaBD — JSON account availability check (read-only).
 *
 *   GET api/check-account.php?username=<name>
 *   GET api/check-account.php?email=<address>
 *
 * Gives register.php and b2b-registration.php their inline "already taken"
 * hints while the visitor types. The POST handler still runs the same checks
 * server-side, so nothing here is a security boundary — it only reports what
 * the duplicate check on submit would have said anyway.
 */
require_once dirname(__DIR__) . '/helpers.php';

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    json_response(['ok' => false, 'error' => 'This endpoint is read-only; use GET.'], 405);
}

if (!db()) {
    json_response(['ok' => false, 'error' => 'The database is offline.'], 503);
}

$username = trim((string) ($_GET['username'] ?? ''));
$email    = trim((string) ($_GET['email'] ?? ''));

if ($username === '' && $email === '') {
    json_response(['ok' => false, 'error' => 'Send a username, an email, or both.'], 400);
}

$result = ['ok' => true];

/* ------------------------------------------------------------------ username */
if ($username !== '') {
    if (!is_valid_username($username)) {
        $result['username'] = [
            'value'   => $username,
            'checked' => true,
            'valid'   => false,
            'taken'   => false,
            'message' => username_rule_text(),
        ];
    } else {
        $taken = db_fetch_all(
            'SELECT UserID FROM `user` WHERE Username = :u LIMIT 1',
            [':u' => $username]
        );
        $result['username'] = [
            'value'   => $username,
            'checked' => true,
            'valid'   => true,
            'taken'   => (bool) $taken,
            'message' => $taken
                ? 'That username is already registered — try signing in instead.'
                : 'Username is available.',
        ];
    }
}

/* --------------------------------------------------------------------- email */
if ($email !== '') {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $result['email'] = [
            'value'   => $email,
            'checked' => true,
            'valid'   => false,
            'taken'   => false,
            'message' => 'That does not look like a valid email address.',
        ];
    } else {
        $taken = db_fetch_all(
            'SELECT UserID FROM `user` WHERE Email = :e LIMIT 1',
            [':e' => $email]
        );
        $result['email'] = [
            'value'   => $email,
            'checked' => true,
            'valid'   => true,
            'taken'   => (bool) $taken,
            'message' => $taken
                ? 'That email already has an account.'
                : 'Email is available.',
        ];
    }
}

json_response($result);
