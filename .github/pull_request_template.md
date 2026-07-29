## Summary

Describe the user-facing problem and the solution in a few sentences.

## Scope

- [ ] The pull request contains one coherent change.
- [ ] Unrelated generated files, local models, logs, and secrets are excluded.
- [ ] Existing application behavior is preserved unless the change is documented.

## Validation

- [ ] `php bin/phpunit`
- [ ] `php bin/console lint:container`
- [ ] `php bin/console lint:twig templates`
- [ ] Database migrations were reviewed when entities changed.
- [ ] Mobile layouts were checked when templates or styles changed.

## Security and privacy

- [ ] No `.env` files, credentials, customer emails, or private menu images are included.
- [ ] New state-changing endpoints validate authorization and CSRF protection.
- [ ] Data collection, retention, or deletion changes are reflected in the policies.

## Screenshots

Add before-and-after screenshots for visible changes, with personal data and credentials hidden.
