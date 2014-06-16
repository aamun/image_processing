<?php
// Requiere configuration
require_once('config.php');
// Requiere S3 class
require_once('S3.class.php');
// Require image library
require_once('Zebra_Image.php');

// Connect to db
$connection = mysqli_connect(HOST,DB_USER,DB_PASSWORD);

// Select db
mysqli_select_db($connection, DB_NAME);

// Connection error
if ($connection->connect_error) {
    die('Connect Error (' . $connection->connect_errno . ') ' . $connection->connect_error);
}

// Find the next 3 images to be processed
$result = mysqli_query($connection, "SELECT * FROM media WHERE status = 3 LIMIT 0,9");

if($result === false){
    echo mysqli_error($connection);
    echo mysqli_errno($connection);
    die;
} else {
    // echo "Result: ".$result;
    // printf("Select returned %d rows.\n", $result->num_rows);
}

// If rows found is more than 0
if (mysqli_num_rows($result) > 0) {
   
    // Create S3 class
    $s3 = new S3(AWS_ACCESS_KEY_ID,AWS_SECRET_ACCESS_KEY, false);

    // S3 Buker name
    $bucket = BUCKET;

    // create a new instance of the class
    $image = new Zebra_Image();

    // since in this example we're going to have a jpeg file, let's set the output
    // image's quality
    $image->jpeg_quality = 100;
    
    // some additional properties that can be set
    // read about them in the documentation
    $image->preserve_aspect_ratio = true;
    $image->enlarge_smaller_images = false;
    $image->preserve_time = true;

    // Thumbnails sizes
    $expectedSizes = array(
        'small' => array(220, 220),
        'medium' => array(640, 400),
        'large' => array(1280, 800)
    );

    // Results
    $processed = array();

    // Images loop
    while ($row = mysqli_fetch_array($result)) {

        // echo ("<pre>");
        // print_r(mysqli_fetch_array($result));
        // echo ("</pre>");

        // print_r($row);

        // Config Path
        $uriFrom = $row['src']."/".$row['filename'];
        $tmpFile = "/tmp/".$row['filename'];
        $tmpThumbnail = '/tmp/thumb_'.$row['filename'];
        

        // Download file from S3
        if($object = $s3->getObject($bucket, $uriFrom, $tmpFile)){
            // print_r($object);
            
            // indicate a source image (a GIF, PNG or JPEG file)
            $image->source_path = $tmpFile;

            // indicate a target image
            // note that there's no extra property to set in order to specify the target
            // image's type -simply by writing '.jpg' as extension will instruct the script
            // to create a 'jpg' file
            $image->target_path = $tmpThumbnail;

            // Thumbnails sizes loop
            foreach ($expectedSizes as $key => $size) {
                // Set thumbnail filename
                $uriTo = $row['src']."/{$key}_".$row['filename'];

                //Added by Radames
                if ($key == "small"){
                    $resizeOption = ZEBRA_IMAGE_NOT_BOXED;
                }else{
                    $resizeOption = ZEBRA_IMAGE_BOXED;
                }

                // Create thumbnail
                if (!$image->resize($size[0], $size[1], $resizeOption)) {
                    // Handle error
                    echo "Error: {$tmpFile} {$size[0]}x{$size[1]}<br>";
                } else {
                    echo "Success: {$tmpFile} ({$uriTo}) {$size[0]}x{$size[1]}<br>";

                    if ($key == "small"){

                        $fileDetail = getimagesize($tmpThumbnail);
                        $thumb_width = $fileDetail[0];
                        $thumb_height = $fileDetail[1];

                        $idProduct = $row['idProduct'];
                        $imageName = $row['filename'];

                        $whereProduct ="";
                        if ($idProduct != 0){
                            $whereProduct = "idProduct = ".$idProduct." AND";
                        }

                        $querySelect = "SELECT * FROM products WHERE ".$whereProduct." image='".$imageName."' AND folder = '".$row['src']."'";

                        echo $querySelect;

                        $resultProducts = mysqli_query($connection, $querySelect);

                        if (mysqli_num_rows($resultProducts) > 0) {
                            $sqlUpdate = "UPDATE products SET thumb_width = ".$thumb_width. ", thumb_height = ". $thumb_height." where ".$whereProduct." image='".$imageName."' AND folder = '".$row['src']."'";

                            echo $sqlUpdate;
                            // update database
                            $resultProducts = mysqli_query($connection, $sqlUpdate);
                        }
                    }

                    // Save to S3
                    if($put = @S3::putObject(
                        S3::inputFile($tmpThumbnail),
                        $bucket,
                        $uriTo,
                        S3::ACL_PUBLIC_READ,
                        array(),
                        array( // Custom $requestHeaders
                            "Cache-Control" => "max-age=315360000",  // 5 Years of cache
                            "Expires" => gmdate("D, d M Y H:i:s T", strtotime("+5 years"))
                        )
                    )){
                    }
                }
            }
            
            // set image as Proccesed
            $processed[] = $row['idMedia'];
        } else {
            echo "$tmpFile not found in S3";
        }
    }

    // If some images has processed
    if (!empty($processed)) {
        // Create where query
        $where = "WHERE idMedia = ";
        $where .= implode(" OR idMedia = ", $processed);

        echo $sql = "UPDATE media SET status = 4 {$where}";
        // update database
        $result = mysqli_query($connection, $sql);
    }
} else {
    echo "Not images to be processed.";
}