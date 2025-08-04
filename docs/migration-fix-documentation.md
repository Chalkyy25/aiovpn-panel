# Migration Fix Documentation

## Issue Description

The application was encountering the following error when attempting to create VPN users:

```
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'expires_at' in 'field list' (Connection: mysql, SQL: insert into `vpn_users` (`username`, `plain_password`, `password`, `expires_at`, `wireguard_private_key`, `wireguard_public_key`, `wireguard_address`, `max_connections`, `updated_at`, `created_at`) values (wg-MkgA08, ?, $2y$12$bMKG9lViOBWLtSoH2ynuS.5xAFZb1n5/7O8.PP0bEep3Wt4N/ulSi, 2025-09-04 17:10:52, , , 10.66.66.155/32, 1, 2025-08-04 17:10:52, 2025-08-04 17:10:52))
```

This error occurred because the application was trying to insert data into an `expires_at` column in the `vpn_users` table, but this column did not exist in the database.

## Investigation

Upon investigation, we found:

1. A migration file `2025_08_04_163944_add_expires_at_to_vpn_users_table.php` had been created to add the `expires_at` column to the `vpn_users` table.
2. The VpnUser model had been updated to include `expires_at` in its `$fillable` array.
3. According to Laravel's migration system, the migration had been run (it appeared in `php artisan migrate:status` as "Ran").
4. However, the column was not actually present in the database, causing the error when the application tried to use it.

## Solution

To resolve this issue, we created a new migration that would add the `expires_at` column only if it didn't already exist:

1. Created a new migration file `2025_08_04_171549_ensure_expires_at_column_exists_in_vpn_users_table.php`
2. Implemented the migration to:
   - Check if the `expires_at` column exists before attempting to add it
   - Add the column as a nullable timestamp after the `password` column if it doesn't exist
   - Include a proper `down()` method to drop the column if the migration is rolled back
3. Ran the migration to add the column to the database
4. Verified the fix by successfully creating a VPN user with an `expires_at` value

## Technical Details

The new migration file contains the following code:

```php
public function up(): void
{
    Schema::table('vpn_users', function (Blueprint $table) {
        if (!Schema::hasColumn('vpn_users', 'expires_at')) {
            $table->timestamp('expires_at')->nullable()->after('password');
        }
    });
}

public function down(): void
{
    Schema::table('vpn_users', function (Blueprint $table) {
        if (Schema::hasColumn('vpn_users', 'expires_at')) {
            $table->dropColumn('expires_at');
        }
    });
}
```

This approach ensures that the column is added if it doesn't exist, preventing errors if the migration is run multiple times or if the column already exists.

## Conclusion

The issue was resolved by ensuring the `expires_at` column exists in the `vpn_users` table. The application can now successfully create VPN users with expiration dates.

This fix addresses the discrepancy between Laravel's migration system (which thought the migration had been run) and the actual state of the database (where the column was missing).
