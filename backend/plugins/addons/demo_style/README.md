# Demo Style Addon

`demo_style` is a EwyFinance addon example based on the ZJMF `public/plugins/addons/demo_style` package shape.

It keeps EwyFinance's current plugin runtime contract:

- `config.php` declares metadata, config schema, scheduled tasks, and schedule hooks.
- `DemoStylePlugin::execute(array $request)` is the only runtime entry.
- Scheduled work is registered through `extra.scheduled_tasks`.
- Hooks are registered through `extra.schedule_hooks`.

The addon does not register global routes, middleware, or system scheduler entries.
