# Elastic Application Resources

Application Hosting plans define included CPU, RAM, and disk allowances. CPU and
RAM are soft Docker reservations: a healthy workload may burst above its plan
allowance, and the excess is charged from collected metrics.

## Runtime policy

- Compose services use `mem_reservation` and relative `cpu_shares`.
- Plan allowances are not emitted as `mem_limit`, `cpus`, or deploy hard limits.
- The allowance is shared across the whole stack, including database and
  frontend/edge sidecars.
- Existing Compose YAML is upgraded in place the next time the stack starts or
  is recreated.

## Capacity safety

New placement uses both sold reservations and live node utilization. Only online
nodes are eligible, and the default protected headroom is:

- RAM: 20%
- CPU: 10%
- Storage: 10%

Configure these with `CONTAINER_NODE_*_HEADROOM_PERCENT`. A node that cannot
preserve headroom receives no new deployments.

At ~70% pressure (live or reserved), `cron:check-container-node-capacity` alerts
admins over Telegram/email to provision another `container_host`. Talksasa does
not create cloud VMs automatically; once the new node is active/online, placement
uses it. Configure with `CONTAINER_NODE_SCALE_OUT_PERCENT` and
`CONTAINER_NODE_SCALE_OUT_COOLDOWN_MINUTES`.

Adding provider-level node autoscaling remains an infrastructure integration.

## Metering and billing

- Metrics aggregate the app and all explicitly named Compose sidecars.
- Docker CPU percentage is converted directly to cores (`100% = 1 core`).
- RAM and CPU percentages may exceed 100% by design.
- Overage invoice items are idempotent and store their metering period.
- A subsequent renewal invoice resumes from the previous snapshot so advance
  invoice generation does not lose the final days of a billing cycle.
- Metrics are retained for 400 days to support annual billing cycles.

## Production rollout

Run migrations before restarting workers:

```bash
php artisan migrate --force
php artisan queue:restart
```

Existing stacks adopt the policy on their next start, recreate, environment
update, or plan change. `cron:auto-restart-containers` also repairs stopped
WordPress database sidecars through the normal Compose start path.
