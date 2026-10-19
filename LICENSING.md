# Licenses for vendored code

This project is a shop storefront, but two third-party libraries are checked into
the repository so the site works without a package-manager install step. They are
unmodified upstream code and must stay unmodified.

## FPDF — `vendor/fpdf/`

Upstream project: <https://github.com/Setasign/FPDF> (version 1.9).

The library is a single file, `vendor/fpdf/fpdf.php`, with the core-font metric
files it needs under `vendor/fpdf/font/` (`helvetica.json`, `times.json`,
`courier.json`, `symbol.json`, `zapfdingbats.json` and their bold/italic
variants). Those metric files are distributed by upstream under the *FPDF Font
Metrics* license.

FPDF's own license is the GNU General Public License version 3, reproduced in
full in [vendor/fpdf/LICENSE](vendor/fpdf/LICENSE). The font metrics carry their
own permission notice, reproduced in
[vendor/fpdf/font/LICENSE](vendor/fpdf/font/LICENSE): they may be used and
reproduced without restriction, including in commercial products, provided the
copyright notices are retained and the fonts themselves are not distributed.

SebaBD's application code does not derive from FPDF; `pdf-document.php` calls it
as a library through its public API. That is a "mere aggregation" of two works,
so the site's own code is not placed under the GPL by this dependency — but if
you distribute this repository, keep both license files with it, as this tree
does.

## KoolReport — `vendor/koolreport/core/`

Used by the staff reports (`reports/*`). See the notice alongside that
directory.
