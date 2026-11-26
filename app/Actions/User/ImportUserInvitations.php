<?php

namespace App\Actions\User;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ImportUserInvitations
{
    public const MAX_ROWS = 200;

    public const MAX_FILE_KB = 128;

    /** @return list<array{id: int, email: ?string, message: ?string, link: ?string, failed: bool}> */
    public function run(Organization $organization, string $csv, string $role, InviteUser $invite): array
    {
        $results = [];
        foreach ($this->parseRows($csv) as $line => $columns) {
            $results[] = $this->importRow($organization, $role, $line, $columns, $invite);
        }

        return $results;
    }

    /** @return array<int, list<string>> */
    private function parseRows(string $csv): array
    {
        if (strlen($csv) > self::MAX_FILE_KB * 1024 || ! mb_check_encoding($csv, 'UTF-8') || str_contains($csv, "\0")) {
            throw ValidationException::withMessages(['batchCsv' => __('Use a UTF-8 CSV up to :limit KB.', ['limit' => self::MAX_FILE_KB])]);
        }

        $csv = str_replace(["\r\n", "\r"], "\n", $csv);
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Could not open an in-memory CSV stream.');
        }

        $rows = [];
        $first = true;
        $line = 1;
        try {
            fwrite($stream, str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv);
            rewind($stream);
            while (($columns = fgetcsv($stream, escape: '')) !== false) {
                $lineSpan = 1 + array_sum(array_map(fn (?string $column): int => substr_count($column ?? '', "\n"), $columns));
                $columns = array_map(fn (?string $column): string => trim($column ?? ''), $columns);
                if ($columns !== [''] && (! $first || ! in_array(array_map(strtolower(...), $columns), [['name', 'email'], ['姓名', '邮箱']], true))) {
                    $rows[$line] = $columns;
                }
                $first = $first && $columns === [''];
                $line += $lineSpan;
                if (count($rows) > self::MAX_ROWS) {
                    throw ValidationException::withMessages(['batchCsv' => __('Import up to :limit users per batch.', ['limit' => self::MAX_ROWS])]);
                }
            }
        } finally {
            fclose($stream);
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['batchCsv' => __('Enter at least one user to invite.')]);
        }

        return $rows;
    }

    /**
     * @param  list<string>  $columns
     * @return array{id: int, email: ?string, message: ?string, link: ?string, failed: bool}
     */
    private function importRow(Organization $organization, string $role, int $line, array $columns, InviteUser $invite): array
    {
        if (count($columns) !== 2) {
            return $this->result($line, null, __('Each CSV row must have a name and an email.'), failed: true);
        }

        [$name, $email] = $columns;
        $validator = Validator::make(compact('name', 'email'), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
        ]);
        if ($validator->fails()) {
            return $this->result($line, $email, $validator->errors()->first(), failed: true);
        }

        if ($this->emailExists($email)) {
            return $this->result($line, $email, __('This email is already registered.'));
        }

        try {
            $user = $invite->run($organization, $name, $email, $role);
        } catch (UniqueConstraintViolationException $exception) {
            if (! $this->emailExists($email)) {
                return $this->failedRow($line, $email, $exception);
            }

            return $this->result($line, $email, __('This email is already registered.'));
        } catch (QueryException $exception) {
            return $this->failedRow($line, $email, $exception);
        }

        return $this->result($line, $email, link: $user->getInvitationUrl());
    }

    /** @phpstan-impure */
    private function emailExists(string $email): bool
    {
        return User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->exists();
    }

    /** @return array{id: int, email: ?string, message: ?string, link: ?string, failed: bool} */
    private function failedRow(int $line, string $email, QueryException $exception): array
    {
        report($exception);

        return $this->result($line, $email, __('Database error while processing this user.'), failed: true);
    }

    /** @return array{id: int, email: ?string, message: ?string, link: ?string, failed: bool} */
    private function result(int $line, ?string $email, ?string $message = null, ?string $link = null, bool $failed = false): array
    {
        return ['id' => $line, 'email' => $email, 'message' => $message, 'link' => $link, 'failed' => $failed];
    }
}
