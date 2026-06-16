# Installing the full AGPL-3.0 license text

LibreStack Panel is licensed under the **GNU Affero General Public License,
version 3 or later** (`AGPL-3.0-or-later`). This is asserted in:

- `composer.json` → `"license": "AGPL-3.0-or-later"`
- `package.json` → `"license": "AGPL-3.0-or-later"`
- `README.md`

The repository currently ships a **short AGPL notice** in `LICENSE` (the build
environment had no network access to fetch the canonical text). Before any
public release you **must** replace `LICENSE` with the complete, verbatim
AGPLv3 text.

## How to install the full license text

From a machine with internet access, run:

```bash
curl -fsSL https://www.gnu.org/licenses/agpl-3.0.txt -o LICENSE
```

or with `wget`:

```bash
wget -O LICENSE https://www.gnu.org/licenses/agpl-3.0.txt
```

Then commit the change:

```bash
git add LICENSE
git commit -m "Add full AGPL-3.0 license text"
```

## Verify

The file should begin with:

```
                    GNU AFFERO GENERAL PUBLIC LICENSE
                       Version 3, 19 November 2007
```

and end with the "How to Apply These Terms to Your New Programs" section.

## Why AGPL?

LibreStack Panel is free forever. The AGPL guarantees that anyone who runs a
modified version of the panel as a network service must offer the corresponding
source to its users. There are no premium features, no license server, and no
telemetry.
