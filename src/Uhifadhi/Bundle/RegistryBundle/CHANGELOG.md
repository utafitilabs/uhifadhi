# Changelog — RegistryBundle

## Contents

- [1.0.0](#100)

## 1.0.0

Not released yet.

 * A MODULE SAYS WHAT IT IS, in one line: `ModuleProviderInterface::description()`
   (null by the trait's default), kept on the catalogue row and printed under
   the module's name by the area's Modules section.

 * the module catalogue, the per-area install ledger and the parking gate
 * the permissions modules declare, collected for an installation to assign
 * `registry:sync`, the command that reconciles the catalogue with the installed module bundles and reports what it added, kept and retired — typed after the migrations and before the warm-up; a request reconciles nothing
