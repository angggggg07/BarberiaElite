<?php
header('Content-Type: text/css');
$file=__DIR__.'/style.css';
if(file_exists($file)){
    echo file_get_contents($file);
}
?>
