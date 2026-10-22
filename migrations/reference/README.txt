Reference database migrations: shared lists that are not patient data (ICD-10 codes, countries, DHA code lists,
starter templates). Every clinic's database user may only SELECT from it. Same naming and rules as tenant/README.txt.
Applied with: php tools/migrate.php --set=reference
