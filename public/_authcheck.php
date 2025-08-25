<?php
header('Content-Type: text/plain');
echo $_SERVER['HTTP_AUTHORIZATION'] ?? 'NO AUTH HEADER';
