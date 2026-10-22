Clinic (tenant) database migrations.

- Name: NNNN_short_description.sql (4 digits, lower-case words with underscores), e.g. 0001_add_branches.sql
- Applied in name order by: php tools/migrate.php   (use --dry-run first)
- Never edit a file after it has been applied anywhere: add a new one. The runner refuses edited files.
- Additive changes only (CREATE ... IF NOT EXISTS, ALTER ... ADD). Dropping or rewriting data needs explicit approval and a backup.
- MySQL commits CREATE/ALTER immediately, so write each file so it is safe to run again if it stopped half-way.
- 0000_baseline.sql (Phase 1) will capture today's schema, including the tables the ensure*Schema() functions create.
