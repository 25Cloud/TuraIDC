# Demo Style Development Notes

ZJMF addons can contain `config`, `controller`, `controller/clientarea`, `lang`, `template/admin`, `template/clientarea`, `template/public`, and `validate`.

EwyFinance keeps that package concept but maps execution into explicit runtime actions:

- `addon.metadata`
- `addon.admin.index`
- `addon.client.index`
- `addon.public.assets`
- `addon.health_check`

Future admin or client pages should call explicit backend actions instead of loading addon controllers dynamically.
