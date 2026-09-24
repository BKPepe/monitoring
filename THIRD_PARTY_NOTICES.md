# Third-party notices

The MIT license in `LICENSE` covers the code written for this repository. The
components below are included in the repository under their own licenses.

| Component | Where | Version | License |
| --- | --- | --- | --- |
| [PHPMailer](https://github.com/PHPMailer/PHPMailer) | `apps/status/lib/` (`PHPMailer.php`, `SMTP.php`, `Exception.php`) | 7.1.1 | [LGPL-2.1](https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html) |

`SMTP.php` and `Exception.php` are identical to upstream v7.1.1.
`PHPMailer.php` differs in one place: `parseAddresses()` tests `$useimap`
without a loose `== true` comparison and carries a `@deprecated` note. The
copyright notices stay
in the file headers, and the files can be replaced with any other copy of
the same library.

The npm packages used by the web apps are listed with their licenses in
`package-lock.json`; they are installed at build time, not stored here.
