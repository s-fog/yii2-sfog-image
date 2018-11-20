<?php

namespace sfog\image;

use Exception;
use Yii;
use yii\imagine\Image as YiiImage;
use yii\web\UploadedFile;

class Image
{
    public $uploadsPath;
    public $thumbsPath;
    public $rootPath;
    public $optimizeDomain = 'http://image.sfog.website';
    public $optimizeFilePath;
    public $ourHost;

    public function __construct() {
        $this->uploadsPath = Yii::getAlias('@uploadPath');
        $this->thumbsPath = Yii::getAlias('@thumbsPath');
        $this->rootPath = Yii::getAlias('@www');
        $this->optimizeFilePath = Yii::getAlias('@www').'/optimize.txt';
        $this->ourHost = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'];
    }

    /*
     * $model -> \yii\db\ActiveRecord
     * $attribute -> where record image path
     * $attributeFile -> type image here
     * $thumbs -> array(0 => '{width}x{height}')
     */
    public function uploadFile($model, $attribute, $attributeFile, $thumbs = array(), $watermark = false) {
        $file = UploadedFile::getInstance($model, $attributeFile);
        $className = $this->getClearClassname($model->className());

        if ($file) {
            $name = $this->generateName($file);
            $extention = $file->extension;
            $filename = '/uploads/'.$name.'.'.$extention;
            $flag = $this->createSourceImage($file, $filename, $_FILES[$className]['type'][$attributeFile], $_FILES[$className]['tmp_name'][$attributeFile]);

            if ($flag) {
                $result = $filename;

                foreach($thumbs as $thumb) {
                    $this->doThumb($filename, $thumb, $watermark);
                }
            } else {
                $result = false;
            }
        } else {
            $result = false;
        }


        if (!empty($_FILES[$className]['name'][$attributeFile])) {
            if ($result) {
                $this->deleteOldImages($model, $attribute);
                return $result;
            }
        } else {
            return $_POST[$className][$attribute];
        }
    }

    public function uploadFileDynamicForm($model, $index, $attribute, $attributeFile, $thumbs = array(), $watermark = false) {
        $file = UploadedFile::getInstance($model, $attributeFile);
        $className = $this->getClearClassname($model->className());
        $attributeFileOrigin = preg_replace('#^\[[0-9]+\](.*)$#siU', '$1', $attributeFile);
        $attributeOrigin = preg_replace('#^\[[0-9]+\](.*)$#siU', '$1', $attribute);

        if ($file) {
            $name = $this->generateName($file);
            $extention = $file->extension;
            $filename = '/uploads/'.$name.'.'.$extention;
            $flag = $this->createSourceImage($file, $filename, $_FILES[$className]['type'][$index][$attributeFileOrigin], $_FILES[$className]['tmp_name'][$index][$attributeFileOrigin]);

            if ($flag) {
                $result = $filename;

                foreach($thumbs as $thumb) {
                    $this->doThumb($filename, $thumb, $watermark);
                }
            } else {
                $result = false;
            }
        } else {
            $result = false;
        }


        if (!empty($_FILES[$className]['name'][$index][$attributeFileOrigin])) {
            if ($result) {
                $this->deleteOldImages($model, $attributeOrigin);
                return $result;
            }
        } else {
            return $_POST[$className][$index][$attributeOrigin];
        }
    }

    /*
     * $file -> UploadedFile instance
     * $filename -> /uploads/{filename}.{extension}
     * $type -> $_FILES[$className]['type'][$attributeFile]
     * $type -> $_FILES[$className]['tmp_name'][$attributeFile]
     */
    public function createSourceImage($file, $filename, $type, $tmp_name) {
        if ($type == 'image/jpeg' || $type == 'image/jpg') {
            try {
                $image = imagecreatefromjpeg($tmp_name);
                $flag = imagejpeg ($image, $this->rootPath.$filename, 75);
                imagedestroy($image);
            } catch (Exception $ex) {
                $flag = $file->saveAs($this->rootPath.$filename, false);
            }
        } else if ($type == 'image/png') {
            try {
                $image = imagecreatefrompng($tmp_name);
                imagealphablending($image, false);
                imagesavealpha($image, true);
                $flag = imagepng($image, $this->rootPath.$filename, 9);
                imagedestroy($image);
            } catch (Exception $ex) {
                $flag = $file->saveAs($this->rootPath.$filename, false);
            }
        } else {
            $flag = $file->saveAs($this->rootPath.$filename, false);
        }

        $this->optimizeImages([$filename]);

        return $flag;
    }

    /*
     * $image -> /uploads/{filename}.{extension}
     * $thumb -> ['{width}x{height}']
     * $watermark -> boolean
     */
    public function doThumb($image, $thumb, $watermark = false) {
        $filename = explode('.', basename($image));
        $thumbCut = explode('x', $thumb);

        if ($watermark) {
            $this->generateWatermark($filename[0], $filename[1], $thumbCut);
        } else {
            $thumbPath = $this->thumbsPath.'/'.$filename[0].'-'.$thumbCut[0].'-'.$thumbCut[1].'.'.$filename[1];

            YiiImage::thumbnail($this->rootPath.$image, $thumbCut[0], $thumbCut[1])
                ->save($thumbPath, ['quality' => 80]);

            $this->optimizeImages([$thumbPath]);
        }
    }

    public function deleteOldImages($model, $attribute) {
        if (!empty($model->oldAttributes[$attribute])) {
            $firstPartOfFilename = basename(explode('.', $model->oldAttributes[$attribute])[0]);

            $uploadPath = $this->uploadsPath;
            $uploadPaths = glob($uploadPath . '/*');
            //echo $firstPartOfFilename;

            foreach ($uploadPaths as $fileItem) {
                if (is_file($fileItem)) {
                    if (strstr($fileItem, $firstPartOfFilename)) {
                        unlink($fileItem);
                    }
                }
            }

            $thumbsPath = $this->thumbsPath;
            $thumbsPaths = glob($thumbsPath . '/*');

            foreach ($thumbsPaths as $fileItem) {
                if (is_file($fileItem)) {
                    if (strstr($fileItem, $firstPartOfFilename)) {
                        unlink($fileItem);
                    }
                }
            }
        }
    }

    /*
     * fullImageName - {$filename}.{$file_ext}
     * $thumbCut -> array(0 => {width}, 1 => {height})
     */
    public function generateWatermark($file_name, $file_ext, $thumbCut, $quality = 80) {
        $thumbPath = $this->thumbsPath.$file_name.'-'.$thumbCut[0].'-'.$thumbCut[1].'.'.$file_ext;
        YiiImage::thumbnail($this->uploadsPath.$file_name.'.'.$file_ext, $thumbCut[0], $thumbCut[1])
            ->save($thumbPath, ['quality' => $quality]);

        $watermarkImage = $this->thumbsPath.$file_name.'-'.$thumbCut[0].'-'.$thumbCut[1].'-watermark.'.$file_ext;
        YiiImage::watermark($thumbPath, $this->rootPath.'/img/watermark-'.$thumbCut[0].'-'.$thumbCut[1].'.png')
            ->save($watermarkImage, ['quality' => $quality]);

        $this->optimizeImages([$watermarkImage]);
        unlink($thumbPath);
    }

    public function getClearClassname($modelClassname) {
        $modelClassName = str_replace('\\', '/', $modelClassname);
        preg_match("#[a-zA-z]+/[a-zA-z]+/([a-zA-z]+)$#siU", $modelClassName, $match);
        return $match[1];
    }

    /*
     * $file -> UploadedFile instance
     */
    public function generateName($file) {
        return md5($file->baseName.time());
    }

    /*
     * $model -> \yii\db\ActiveRecord
     * $attribute -> where record image path
     * $base64 -> image base64
     * $thumbs -> array(0 => '{width}x{height}')
     */
    public function createImageFromBase64($model, $attribute, $base64, $thumbs = []) {
        preg_match('#^data:image/(\w+);base64,(.*)$#i', $base64, $match);

        switch($match[1]) {
            case 'jpg': {
                $type = 'jpg';
                break;
            }
            case 'jpeg': {
                $type = 'jpg';
                break;
            }
            case 'png': {
                $type = 'png';
                break;
            }
            default: $type = 'jpg';
        }

        $data = base64_decode($match[2]);
        $md5 = md5(time() . '-' . rand(100000, 999999));
        $filename = '/uploads/'.$md5.'.'.$type;

        if (file_put_contents($this->rootPath.$filename, $data)) {
            if (!empty($thumbs)) {
                foreach($thumbs as $thumb) {
                    $this->doThumb($filename, $thumb);
                }
            }

            $this->deleteOldImages($model, $attribute);
            $images = [$filename];
            $this->optimizeImages($images);

            return $filename;
        } else {
            return 'error';
        }
    }

    /*
     * $images = [$image], $image -> /uploads/{filename}.{extension} or /images/thumbs/{filename}.{extension}
     */
    public function optimizeImages($images) {
        $imagesChange = [];

        foreach($images as $filename) {
            $basename = basename($filename);
            $tmpImagePath = $this->rootPath.'/'.$basename;

            if (preg_match('#-[0-9]+-[0-9]+#', $basename)) {// it is thumb
                copy($this->thumbsPath.'/'.$basename, $tmpImagePath);
            } else {// not thumb
                copy($this->uploadsPath.'/'.$basename, $tmpImagePath);
            }

            if (file_exists($tmpImagePath)) {
                file_put_contents($this->optimizeFilePath, "/{$basename}||", FILE_APPEND | LOCK_EX);
                $imagesChange[] = "{$this->rootPath}/{$basename}";
            }
        }

        if (file_exists($this->optimizeFilePath)) {
            $ch = curl_init($this->optimizeDomain.'/optimize-upload');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "gyt=1&domain={$this->ourHost}");
            curl_exec($ch);

            unlink($this->optimizeFilePath);

            foreach($imagesChange as $i) {// /{filename}.{extension}
                $b = explode('.', basename($i));
                $filename = $b[0];
                $type = $b[1];

                if (preg_match('#-[0-9]+-[0-9]+#', $i)) {// it is thumb
                    try {
                        copy("{$this->optimizeDomain}/thumbs/{$filename}-min.{$type}", "{$this->thumbsPath}/{$filename}.{$type}");
                        copy("{$this->optimizeDomain}/thumbs/{$filename}-min.webp", "{$this->thumbsPath}/{$filename}.webp");
                    } catch (Exception $e) {

                    }
                } else {// not thumb
                    try {
                        copy("{$this->optimizeDomain}/thumbs/{$filename}-min.{$type}", "{$this->uploadsPath}/{$filename}.{$type}");
                        copy("{$this->optimizeDomain}/thumbs/{$filename}-min.webp", "{$this->uploadsPath}/{$filename}.webp");
                    } catch (Exception $e) {

                    }
                }

                unlink($i);
            }
        }
    }
}
