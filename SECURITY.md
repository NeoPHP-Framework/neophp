# Security policy

## Supported versions

| Version | Security fixes |
|---|---|
| latest `v1.x` release | yes |
| older `v1.x` releases | no: upgrade to the latest `v1.x` (no breaking change inside v1) |

## Reporting a vulnerability

Do **not** open a public issue for a security problem.

Report it privately through GitHub: **Security** tab of the repository, then **Report a vulnerability** (private vulnerability reporting). Please include:

- the affected version (`composer show neophp/framework`) and feature (component or package);
- the steps or the code to reproduce the problem;
- the impact you expect (data exposed, code execution, access bypass...).

You will get an answer within 7 days. The fix is released on the latest `v1.x` version, then the advisory is published with credit to the reporter (unless you prefer to stay anonymous).

## Good practices for applications

- Production: `APP_ENV=prod`, `APP_DEBUG=0`, a unique `APP_SECRET` in `.env.local` or in the server environment (never committed).
- Set `TRUSTED_HOSTS`, and `TRUSTED_PROXIES` behind a reverse proxy.
- Keep `security_headers` enabled and add `hsts` once HTTPS works.
- Store uploaded files outside `public/` and check their type with `UploadedFile::getMimeType()`.
- Run `composer update neophp/framework` regularly to receive the security fixes.