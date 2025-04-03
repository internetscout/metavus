This directory contains the parts of the Filepond upload library that we're
using for Metavus.

More on Filepond at https://pqina.nl/filepond/

Components included are:

  - [Core filepond](https://github.com/pqina/filepond)
  - [jquery-filepond](https://github.com/pqina/jquery-filepond)
  - [filepond-server-php](https://github.com/pqina/filepond-server-php)

The css and js files from the first two were moved into the `css` and `js`
subdirectories. The server component was placed in `server`.

As we're not using the Doka image transformation library, that subdirectory was
removed from `server`.

The `server/config.php` was set up to look for the correct form field names and
to configure the path for the `transfer` dir where upload files are stored.
