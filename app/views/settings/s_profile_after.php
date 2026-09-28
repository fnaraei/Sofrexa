<?php /** Hidden logo upload form (SE5 "Logo yükle" / SE6 "Değiştir" open its file picker). */ ?>
<form id="logo-form" method="post" action="/settings/profile/logo" enctype="multipart/form-data" data-ajax hidden>
  <?= csrf_field() ?>
  <input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/svg+xml" data-logo-file>
</form>
