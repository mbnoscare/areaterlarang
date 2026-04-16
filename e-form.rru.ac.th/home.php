<?php
include ("config/db_config.php");
include ("config/sql_commandPHP7.php");
include ("config/const.php");
db_connect();
session_start();
//RECEIVED from enrol.html
$enr = isset($_GET['enr']) ? addslashes($_GET['enr']) : ''; // safer index usage
//GET TIMESTAMP for check data
$realsubmit = time();
$showtm = date("Y-m-d H:i:s",$realsubmit);

function get_profile($UID){
    $UID = (int)$UID;
    $sql = "SELECT * FROM cr_profile WHERE user_id={$UID} AND del_flag=0";
    $query = db_query($sql);
    $res = db_fetch_array($query);
    return $res;
}

function get_course($realsubmit){
    // $realsubmit not used in query, left for backward compatibility
    $sql = "SELECT * FROM cr_course WHERE del_flag=0 ORDER BY enrol_start DESC";
    $query = db_query($sql);
    return $query;
}

function get_enrol($course_id, $user_id){
    $course_id = (int)$course_id;
    $user_id = (int)$user_id;
    $sql = "SELECT * FROM cr_enrol WHERE course_id={$course_id} AND user_id={$user_id} AND del_flag=0";
    $query = db_query($sql);
    $res = db_fetch_array($query);
    return isset($res['enrol_status']) ? $res['enrol_status'] : 0;
}

function get_estatus($enrol_sts){
    $enrol_sts = (int)$enrol_sts;
    $sql = "SELECT enrol_status FROM cr_enrol_status WHERE enrol_sid={$enrol_sts} AND del_flag=0";
    $query = db_query($sql);
    $res = db_fetch_array($query);
    return isset($res['enrol_status']) ? $res['enrol_status'] : '';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="">
    <meta name="author" content="G-Gratitude Co.,Ltd.">
    <meta name="generator" content="Hugo 0.87.0">
    <title>RRU Training Database</title>

    <link rel="canonical" href="https://getbootstrap.com/docs/5.1/examples/album/">

    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script> 
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.16.0/umd/popper.min.js"></script> 
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>

    <!-- Bootstrap core CSS -->
    <link href="css/bootstrap.min.css" rel="stylesheet"><!--assets/dist/-->

    <style>
    /* ... your styles unchanged ... */
      .bd-placeholder-img {
        font-size: 1.125rem;
        text-anchor: middle;
        -webkit-user-select: none;
        -moz-user-select: none;
        user-select: none;
      }
      /* unvisited link */
a:link { color: #19BBAF; text-decoration: none; }
a:visited { color: #19BBAF; text-decoration: none; }
a:hover { color: #19BBAF; text-decoration: none; }
a:active { color: #19BBAF; text-decoration: none; }

.btn {
  border: 2px solid black;
  background-color: white;
  color: #19BBAF;
  border-color: #19BBAF;
  padding: 8px 8px;
  font-size: 14px;
  cursor: pointer;
  border-radius: 5px;
}
.btn:hover {
  background: #19BBAF;
  color: white;
  border-color: #19BBAF;
}
      @media (min-width: 768px) {
        .bd-placeholder-img-lg {
          font-size: 3.5rem;
        }
      }
    </style>

    <link href="css/headers.css" rel="stylesheet">
    <link href="css/footers.css" rel="stylesheet">
    <link href="css/carousel.css" rel="stylesheet">
</head>
<body>
<!-- (the rest of your HTML remains unchanged) -->
<svg xmlns="http://www.w3.org/2000/svg" style="display: none;">
  <!-- svg symbols omitted here for brevity but keep exactly as in your original -->
  <!-- ... -->
</svg>

<header class="p-3 mb-3 border-bottom">
    <div class="container">
        <div class="d-flex flex-wrap align-items-center justify-content-center justify-content-lg-start">
            <a href="index.php" class="d-flex align-items-center mb-2 mb-lg-0 text-dark text-decoration-none">
            <img src="images/logorru.png" width="30" border="0"></a>

            <ul class="nav col-12 col-lg-auto me-lg-auto mb-2 justify-content-center mb-md-0">
                <li><a href="index.php" class="nav-link px-2 link-secondary">Home</a></li>
                <li><a href="about.php" class="nav-link px-2 link-dark">About</a></li>
                <li><a href="contact.php" class="nav-link px-2 link-dark">Contact</a></li>
            </ul>
<?php
//RECEIVED from signin.php
if(isset($_SESSION["UID"])){
    $login_err = isset($_POST['login_err']) ? addslashes($_POST['login_err']) : 0;
    $UID = isset($_SESSION['UID']) ? addslashes($_SESSION['UID']) : '';
    if($login_err == 0){
        $res_prof = get_profile($UID);
?>
      <ul class="nav">
        <img src="<?php echo isset($res_prof['picture']) ? $res_prof['picture'] : 'images/default.png';?>" alt="mdo" width="32" height="32" class="rounded-circle">
        <li class="nav-item"><a href="signout.php?UID=<?php echo urlencode($UID);?>" class="nav-link link-dark px-2">Sign out</a></li>
      </ul>
<?php
    }
}else{
?>
      <ul class="nav">
        <li class="nav-item"><a href="signin.php" class="nav-link link-dark px-2">Login</a></li>
        <li class="nav-item"><a href="register.php" class="nav-link link-dark px-2">Sign up</a></li>
      </ul>
<?php
}
?>
        </div>
    </div>
</header>

<main>
    <div id="carouselExampleDark" class="carousel carousel-dark slide" data-bs-ride="carousel">
        <div class="carousel-indicators">
            <button type="button" data-bs-target="#carouselExampleDark" data-bs-slide-to="0" class="active" aria-current="true" aria-label="Slide 1"></button>
            <button type="button" data-bs-target="#carouselExampleDark" data-bs-slide-to="1" aria-label="Slide 2"></button>
            <button type="button" data-bs-target="#carouselExampleDark" data-bs-slide-to="2" aria-label="Slide 3"></button>
        </div>
        <div class="carousel-inner">
            <div class="carousel-item active" data-bs-interval="10000">
                <img src="images/training/banner1.jpg" class="d-block w-100" alt="...">
            </div>
            <div class="carousel-item" data-bs-interval="2000">
                <img src="images/training/banner2.jpg" class="d-block w-100" alt="...">
            </div>
            <div class="carousel-item">
                <img src="images/training/banner3.jpg" class="d-block w-100" alt="...">
            </div>
        </div>
        <button class="carousel-control-prev" type="button" data-bs-target="#carouselExampleDark" data-bs-slide="prev">
            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
            <span class="visually-hidden">Previous</span>
        </button>
        <button class="carousel-control-next" type="button" data-bs-target="#carouselExampleDark" data-bs-slide="next">
            <span class="carousel-control-next-icon" aria-hidden="true"></span>
            <span class="visually-hidden">Next</span>
        </button>
    </div>

    <div class="album py-5 bg-light">
        <div class="container">
            <div style="padding:10px;"><h3>รายการวิชาที่เปิดให้ลงทะเบียน / List of Courses</h3></div>

<?php
if($enr == "f"){
?>
<div class="alert alert-danger alert-dismissible fade show">
    <button type="button" class="close" data-dismiss="alert">&times;</button>
    <strong>ผิดพลาด !</strong> ไม่สามารถลงทะเบียนได้, โปรดลองใหม่อีกครั้ง<br>
    <strong>Error !</strong> Cannot enrol, please try again.
</div>
<?php
}elseif($enr == "c"){
?>
<div class="alert alert-success alert-dismissible">
  <button type="button" class="close" data-dismiss="alert">&times;</button>
  <strong>สำเร็จ !</strong> ลงทะเบียนเรียบร้อยแล้ว, กรุณารอตรวจสอบคุณสมบัติ<br>
  <strong>Success !</strong> Enrol completed, please wait for process.
</div>
<?php
}
?>

            <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 g-3">
<?php
$q_course = get_course($realsubmit);
$num_course = db_num_rows($q_course);
if($num_course > 0){
    for($nc=1;$nc<=$num_course;$nc++){
        $res_course = db_fetch_array($q_course);
?>
                <div class="col">
                    <div class="card shadow-sm">
                        <?php if (!empty($res_course['course_cover'])) { ?>
                            <img src="<?php echo $res_course['course_cover'];?>" width="100%" height="225">
                        <?php } else { ?>
                            <svg class="bd-placeholder-img card-img-top" width="100%" height="225" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Placeholder: Thumbnail" preserveAspectRatio="xMidYMid slice" focusable="false"><title>Placeholder</title><rect width="100%" height="100%" fill="#55595c"/><text x="50%" y="50%" fill="#eceeef" dy=".3em">Thumbnail</text></svg>
                        <?php } ?>
                        <div class="card-body">
                            <p class="card-text"><a href="view_course.php?u=<?php echo urlencode($res_course['course_id']);?>" ><?php echo $res_course['course_name']; ?></a><br><?php echo $res_course['course_desc'];?></p>
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="btn-group">
                                <?php
                                    if(isset($_SESSION["UID"]) && !empty($_SESSION["UID"])){
                                        $enrol_sts = get_enrol($res_course['course_id'], $_SESSION["UID"]);
                                        $res_sts = get_estatus($enrol_sts);
                                        if($enrol_sts != 0){
                                            if($enrol_sts == 1){    echo "<font color='#FF6600'>".$res_sts."</font>";    }
                                            elseif($enrol_sts == 2){    echo "<font color='#009933'>".$res_sts."</font>";    }
                                            elseif($enrol_sts == 3){    echo "<font color='#CC0000'>".$res_sts."</font>";    }
                                            else{    echo $res_sts;    }
                                        }else{
                                            if($res_course['tm_course_end'] < $realsubmit){
                                                echo "<font color='#CC0000'>ติดตามผลการเรียน</font>";
                                            }elseif($res_course['tm_enrol_end'] < $realsubmit){
                                                echo "<font color='#CC0000'>ปิดการลงทะเบียน</font>";
                                            }elseif($res_course['tm_enrol_start'] > $realsubmit){
                                                echo "<font color='#FF6600'>ยังไม่เปิดให้ลงทะเบียน</font>";
                                            }else{
                                ?>
                                    <a href="enrol.php?CID=<?php echo urlencode($res_course['course_id'])?>"><button type="button" class="btn btn-sm btn-outline-secondary">Enrol / ลงทะเบียน</button></a>
                                <?php
                                            }
                                        }
                                    }else{
                                ?>
                                    <a href="signin.php"><button type="button" class="btn btn-sm btn-outline-secondary">Enrol / ลงทะเบียน</button></a>
                                <?php
                                    }
                                ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
<?php
    } // end for
} // end if num_course
?>
            </div>
        </div>
    </div>

</main>
<div class="container">
  <footer class="d-flex flex-wrap justify-content-between align-items-center py-3 my-4 border-top">
    <div class="col-md-4 d-flex align-items-center">
      <span class="text-muted">&copy; 2021 Rajabhat Rajanagarindra University</span>
    </div>

    <ul class="nav col-md-4 justify-content-end list-unstyled d-flex">
      <li class="ms-3"><a class="text-muted" href="mailto:office.rru.ac.th" target="_blank"><svg class="bi" width="24" height="24"><use xlink:href="#envelope-open"/></svg></a></li>
      <li class="ms-3"><a class="text-muted" href="https://www.facebook.com/rru.ac.th/" target="_blank"><svg class="bi" width="24" height="24"><use xlink:href="#facebook"/></svg></a></li>
      <li class="ms-3"><a class="text-muted" href="https://line.me/ti/p/~rru.ac.th/" target="_blank"><img src="images/line.jpg" border="0" width="24" height="24"></a></li>
    </ul>
  </footer>
</div>

<script src="js/bootstrap.bundle.min.js"></script>
</body>
</html>

