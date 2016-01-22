<?php
// declare(encoding = 'utf-8');
setlocale(LC_ALL, 'fr_FR.utf8');
mb_internal_encoding("UTF-8");

if (realpath($_SERVER['SCRIPT_FILENAME']) != realpath(__FILE__)); // file is include do nothing
else if (php_sapi_name() == "cli") {
  Dramaturgie_Base::cli();
}
class Dramaturgie_Base {
  /** Lien à une base SQLite, unique */
  public $pdo;
  /** Document XML */
  private $_dom;
  /** Processeur xpath */
  private $_xpath;
  /** Processeur xslt */
  private $_xslt;
  /** Couleurs de nœuds */

  public $ncols = array(
    "#FF4C4C",
    "#A64CA6",
    "#4C4CFF",
    "#4c4ca6",
    "#A6A6A6",
  );


  /** Couleurs de liens */
  public $ecols = array(
    "rgba(255, 0, 0, 0.5)",
    "rgba(128, 0, 128, 0.5)",
    "rgba(0, 0, 255, 0.5)",
    "rgba(0, 0, 128, 0.5)",
    "rgba(140, 140, 160, 0.6)",
  );

  /**
   * Charger un fichier XML
   */
  public function __construct($sqlitefile=null) {
    if ($sqlitefile) $this->connect($sqlitefile);
  }
  /** Charger un fichier XML */
  public function load($xmlfile) {
    $this->_dom = new DOMDocument();
    $this->_dom->preserveWhiteSpace = false;
    $this->_dom->formatOutput=true;
    $this->_dom->substituteEntities=true;
    $this->_dom->load($xmlfile, LIBXML_NOENT | LIBXML_NONET | LIBXML_NSCLEAN | LIBXML_NOCDATA | LIBXML_COMPACT | LIBXML_PARSEHUGE | LIBXML_NOERROR | LIBXML_NOWARNING);
    $this->_xpath = new DOMXpath($this->_dom);
    $this->_xpath->registerNamespace('tei', "http://www.tei-c.org/ns/1.0");
    $this->_xslt = new XSLTProcessor();
  }
  
 
  /** Connexion à la base */
  private function connect($sqlite='basedrama.sqlite') {
    $sql = 'dramaturgie.sql';
    $dsn = "sqlite:" . $sqlite;
    // si la base n’existe pas, la créer
    if (!file_exists($sqlite)) { 
      if (!file_exists($dir = dirname($sqlite))) {
        mkdir($dir, 0775, true);
        @chmod($dir, 0775);  // let @, if www-data is not owner but allowed to write
      }
      $this->pdo = new PDO($dsn);
      $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
      @chmod($sqlite, 0775);
      $this->pdo->exec(file_get_contents(dirname(__FILE__).'/'.$sql));
    }
    else {
      $this->pdo = new PDO($dsn);
      $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
    }
    // table temporaire en mémoire
    $this->pdo->exec("PRAGMA temp_store = 2;");
  }
  /**
   * Produire fichier de nœuds et de relations
   */
  public function gephi($filename) {
    $data = $this->nodes($filename);
    $f = $filename.'-nodes.csv';
    $w = fopen($f, 'w');
    for ($i=0; $i<count($data); $i++) {
      fwrite($w, implode("\t", $data[$i])."\n");
    }
    fclose($w);
    echo $f.'  ';
    $data = $this->edges($filename);
    $f = $filename.'-edges.csv';
    $w = fopen($f, 'w');
    for ($i=0; $i<count($data); $i++) {
      fwrite($w, implode("\t", $data[$i])."\n");
    }
    fclose($w);
    echo $f."\n";
  }
  /**
   * Json compatible avec la librairie sigma.js
   */
  public function sigma($play) {
    $color = array();
    echo "{ nodes: [\n";
    $data = $this->nodes($play);
    // 'Id', 'Label', 'c', 'title', 'targets', 'color'
    $count = count($data) - 1;
    for ($i=1; $i<count($data); $i++) {
      // ne pas mettre les personnages non liés, écarte topr le réseau
      if (!$data[$i][4]) continue;
      $col = "";
      // position initiale en cercle, en diagonale
      $angle = 2*M_PI/3 + (2*M_PI/$count) * ($i -1); 
      // $angle =  2*M_PI/$count * ($i -1);
      $x =  number_format(cos($angle)*6, 4);
      $y =  number_format(sin($angle)*6, 4);
      /*
      // position initiale en ligne
      // $x = $i ; 
      $y = 1;
      // $x = -$i*(1-2*($i%2));
      $x=$i;
      */
      if (isset($this->ncols[$i-1])) {
        $color[$data[$i][0]] = $this->ecols[$i-1];
        $col = ", color: '".$this->ncols[$i-1]."'";
      }
      $json_options = JSON_UNESCAPED_UNICODE;
      echo "  {id:'".$data[$i][0]."', label:".json_encode($data[$i][1],  $json_options).", size:".(0+$data[$i][2]).", x: $x, y: $y".$col.", title: ".json_encode($data[$i][3],  $json_options)."}";
      if ($i+1<count($data)) echo ',';
      echo "\n";
    }
    echo "], edges: [\n";
    $data = $this->edges($play);
    for ($i=1; $i < count($data); $i++) {
      $col = "";
      if (isset($color[$data[$i][0]])) $col = ", color: '".$color[$data[$i][0]]."'";
      // bigger less opacity ?
      // number_format(0.5+(1-$data[$i][3])*0.5, 1)
      // $col=", color: 'rgba(128, 128, 128, 0.5)'";
      echo "  {id:'e".$i."', source:'".$data[$i][0]."', target:'".$data[$i][1]."', size:".$data[$i][2].$col.", type:'drama'}";
      echo ',';
      echo "\n";
    }
    echo "]};\n";
  }
  /**
   * Ligne bibliographique pour une pièce
   */
  public function bibl($play) {
    if (is_string($play)) {
      $playcode = $this->pdo->quote($playcode);
      $play = $this->pdo->query("SELECT * FROM play WHERE code = $playcode")->fetch();
    }
    $bibl = $play['author'].', '.$play['title'];
    $meta = array();
    if ($play['year']) $meta[] = $play['year'];
    if ($play['genre'] == 'tragedy') $meta[] = 'tragédie';
    else if ($play['genre'] == 'comedy') $meta[] = 'comédie';
    if ($play['acts']) $meta[] = $play['acts'].(($play['acts']>2)?" actes":" acte");
    $meta[] = (($play['verse'])?"vers":"prose");
    if (count($meta)) $bibl .= " (".implode(", ", $meta).")";
    return $bibl;
  }
  /**
   * Rythme dramatique, taile moyenne des répliques
   */
  public function dramarhythm($playcode) {
    $playcode = $this->pdo->quote($playcode);
    $play = $this->pdo->query("SELECT * FROM play WHERE code = $playcode")->fetch();
    $step = 1000;
    $act = '';
    $scene = '';
    $splast = null;
    $q = $this->pdo->prepare("SELECT id, * FROM sp WHERE play = $playcode AND cn >= ? ORDER BY play, cn LIMIT 1");
    echo '
<style>
.dramarhythm a { background: #CCCCCC; display: block; float: left; text-decoration: none; width: 15px; white-space: nowrap; }
</style>';
    echo '<div class="dramarhythm">'."\n";
    for ($cn =0; $cn <= $play['c']; $cn = $cn + $step) {
      $q->execute(array($cn));
      $sp = $q->fetch();
      if (!$sp) break;
      if($splast) {
        $height = 1+ ($sp['id'] - $splast['id']);
        echo '<a href="#'.$splast['code'].'" style="height: '.$height.'ex;"> </a>';
      }
      $splast = $sp;
    }
    echo '</div>'."\n";
  }
  public function roletable($playcode) {
    echo '<table class="sortable">'."\n";
    echo '  <caption>'.$this->bibl($playcode)."</caption>\n";
    echo '  <tr><th>Personnages</th><th>Réplques</th><th>Vers</th><th>Mots</th><th>caractère</th></tr>';
    foreach ($this->pdo->query("SELECT * FROM role WHERE play = $playcode ORDER BY c DESC") as $role) {
      $dist[$role['code']] = array(
        'label'=>$role['label'], 
        'sp' => $role['sp'], 
        'w' => $role['w'], 
        'c' => $role['c']
      );
    }
  }
  
  /**
   * Chiffres par rôle
   */
  public function rolerate($playcode, $max=1200) {
    $playcode = $this->pdo->quote($playcode);
    if(! (0+$max)) $max=1000;
    $play = $this->pdo->query("SELECT * FROM play WHERE code = $playcode")->fetch();
    $playwidth = $play['c'] / (100000/$max);
    
    echo '
<style>
.rolerate { font-family: sans-serif; font-size: 15px; }
.rolerate, .rolerate * { box-sizing: border-box; }
div.rolerate { white-space: nowrap; border-bottom: 1px solid #FFFFFF; width: '.floor($playwidth+120).'px;  }
div.rolerate:after { content:""; display: table; clear: both; }
.rolerate label { text-align: right; width: 90px; padding: 0 1ex 0 0; float: left; display: block}
.rolerate .role { overflow: hidden; background-color: rgba(192, 192, 192, 0.7); color: rgba(0, 0, 0, 0.5); font-stretch: ultra-condensed; float: left; height: 2em; border-left: 1px #FFFFFF solid; }
.rolerate .role1 { background-color: rgba(255, 0, 0, 0.5); border-bottom: none; color: rgba(255, 255, 255, 1);}
.rolerate .role2 { background-color: rgba(128, 0, 128, 0.5); border-bottom: none; color: rgba(255, 255, 255, 1);}
.rolerate .role3 { background-color: rgba(0, 0, 255, 0.5); border-bottom: none; color:  rgba(255, 255, 255, 1);}
.rolerate .role4 { background-color: rgba(0, 0, 128, 0.5); border-bottom: none; color:  rgba(255, 255, 255, 1);}
.rolerate .role5 { background-color: rgba(128, 128, 128, 0.5); border-bottom: none; color: rgba(255, 255, 255, 1); }
</style>
    ';
    $dist = array();
    foreach ($this->pdo->query("SELECT * FROM role WHERE play = $playcode ORDER BY c DESC") as $role) {
      $dist[$role['code']] = array(
        'label'=>$role['label'], 
        'sp' => $role['sp'], 
        'w' => $role['w'], 
        'c' => $role['c']
      );
    }
    foreach (array('c', 'w', 'sp') as $unit) {
      echo '<div class="rolerate">'."\n";
      echo '  <label class="unit">'."\n";
      if ($unit=='c') echo "Caractères";
      else if ($unit=='w') echo "Mots";
      if ($unit=='sp') echo "Répliques";
      echo '</label>';
      $i=1;
      foreach ($dist as $code=>$stats) {
        $width = round($playwidth*$stats[$unit]/$play[$unit]);
        echo '<span class="role role'.$i.'" title="'.$stats['label'].' " style="width: '.$width.'px"> '.$stats['label'].' </span>';
        $i++;
      }
      echo '</div>';
    }
  }
  
  /**
   * Panneau vertical de pièce
   */
  public function charline($playcode, $width=150, $heightref=800) {
    $scenewidth = $width - 80;
    echo '
<style>
.charline { font-family: sans-serif; font-size: 13px; line-height: 1.2em; position: relative; }
.charline, .charline * { box-sizing: border-box; }
.charline:after, .charline .act:after, .charline .scene:after, .charline .cast:after, .charline .sps:after { content:""; display:table; clear:both; }
.charline a { border-bottom: none; text-decoration: none; }
.charline a:hover { background: transparent; }

b.n { position: absolute; left: 0; font-weight: bold; color: #999; }

.charline div.scene { border-bottom: 1px solid rgba(255, 255, 255, 0.1); }
.charline .acthead { display: block; text-align: center; padding: 5px  30px 1px 0; clear: both;  }
.charline a.cast { display: block; height: 100%; float: left; padding-left: 3ex; }
.charline div.sps { margin-left: 5px; margin-bottom: 1px; float: right; width: 35px; border-right: 1px dotted #CCCCCC; background: #FFFFFF; }
.charline div.sps a { display: block; line-height: 3px; position: relative; }
.charline div.sps b { background: #AAAAAA; display: block; }
.charline .role { float: left; height: 100%; border-left: 1px rgba(255, 255, 255, 0.5) solid; border-radius: 3px/1em; font-stretch: ultra-condensed; }
.charline .role span { overflow: hidden; padding-left: 1ex; padding-top: 2px;}

</style>
    ';
    $playcode = $this->pdo->quote($playcode);
    $play = $this->pdo->query("SELECT * FROM play WHERE code = $playcode")->fetch();
    // 1 pixel = 1000 caractères
    if (!$heightref) $playheight = '800';
    else if (is_numeric($heightref) && $heightref > 50) $playheight = round($play['c'] / (100000/$heightref));
    else $playheight = '800';
    
    
    // requête sur le nombre de caractère d’un rôle dans une scène
    $qsp = $this->pdo->prepare("SELECT sum(c) FROM sp WHERE play = $playcode AND scene = ? AND source = ?");
    $qcn = $this->pdo->prepare("SELECT * FROM sp WHERE play = $playcode AND scene = ? AND cn <= ? ORDER BY play, cn DESC LIMIT 1");
    echo '<div class="charline">'."\n";
    
    // loop on acts
    foreach ($this->pdo->query("SELECT * FROM act WHERE play = $playcode ORDER BY rowid") as $act) {
      echo '  <a href="#'.$act['code'].'" class="acthead">'.$act['label']."</a>\n";
      echo '  <div class="act">'."\n";
      $actheight = $playheight * $act['c']/$play['c'];
      // loop on scene
      foreach ($this->pdo->query("SELECT * FROM scene WHERE play = $playcode AND act = ".$this->pdo->quote($act['code'])) as $scene) {
        $sceneheight = 3+ ceil($actheight * $scene['c']/$act['c']);
        if (!isset($scene['n'])) $scene['n'] = 0+ preg_replace('/\D/', '', $scene['code']);
        // scene cont
        echo '    <div class="scene" style="height: '.($sceneheight +1).'px;" title="Acte '.$scene['act'].', scène '.$scene['n'].'">'."\n";
          echo '      <b class="n">'.$scene['n'].'</b>'."\n";
          // role bar
          echo '      <a href="#'.$scene['code'].'" class="cast">'."\n";
          $i = 0;
          // loop on role
          foreach ($this->pdo->query("SELECT * FROM role WHERE play = $playcode ORDER BY c DESC") as $role) {
            $qsp->execute(array($scene['code'], $role['code']));
            list($c) = $qsp->fetch();
            $i++;
            if (!$c) continue;
            $rolewidth = number_format($scenewidth * $c / $scene['c']) ;
            echo '<span class="role role'.$i.' '.$role['rend'].'"';
            echo ' style="width: '.$rolewidth.'px"';
            echo ' title="'.$role['label'].', acte '.$scene['act'].', scène '.$scene['n'].', '.round(100*$c / $scene['c']).'%"';
            echo '>';
            if ($rolewidth > 35 && $sceneheight > 12 ) { // && !isset($list[$role['code']])
              echo '<span>'.$role['label'].'</span>';
              $list[$role['code']] = true;
            }
            else echo ' ';
            echo '</span>';
          }
          echo "      </a>\n";
          echo '      <div class="sps">';
          $splast = null;
          for ($pixel = 0; $pixel <= $sceneheight; $pixel = $pixel +3) {
            $cn = $scene['cn'] + ceil($scene['c'] * $pixel / $sceneheight);
            $qcn->execute( array($scene['code'], $cn));
            $sp = $qcn->fetch();
            if (!$sp) $sp = $splast;
            if($splast) {
              $width = 3*($sp['id'] - $splast['id']);
              echo '<a href="#'.$splast['code'].'"><b style="width: '.$width.'px"> </b></a>';
            }
            $splast = $sp;
          }
          echo "      </div>\n";
 
        echo "    </div>\n";
      }
      echo "  </div>\n";
    }
    echo "</div>\n";
  }

  public function timebars($playcode, $max=null) {
    
    echo '
<style>
.timebars, .timebars * { box-sizing: border-box; }
.timebars:after, .timebars:after { content:""; display:table; }
.timebars:after { clear:both; }
.timebars { zoom:1; margin-top: 1.5em; }
.timebars { height: 200px; font-family: sans-serif;  }
.timebars .act { position: absolute; margin-top: -1.1em;  border-left: 3px #000 solid; padding-left: 1ex; margin-bottom: 1em; white-space: nowrap;}
.timebars .scene { float: left; height: 100%;}
.timebars .scene1 {  }
.timebars .role { background-color: rgba(192, 192, 192, 0.7); border-radius: 3px/0.5em; border-bottom: 1px solid #FFFFFF; color: rgba(0, 0, 0, 0.5); font-stretch: ultra-condensed; font-size: 13px; }
.timebars .role1 { background-color: rgba(255, 0, 0, 0.7); border-bottom: none; color: rgba(255, 255, 255, 0.7);}
.timebars .role2 { background-color: rgba(128, 0, 128, 0.7); border-bottom: none; color: rgba(255, 255, 255, 0.7);}
.timebars .role3 { background-color: rgba(0, 0, 255, 0.7); border-bottom: none; color: rgba(255, 255, 255, 0.7);}
.timebars .role4 { background-color: rgba(0, 0, 128, 0.7); border-bottom: none; color: rgba(255, 255, 255, 0.7);}
.timebars .role5 { background-color: rgba(128, 128, 128, 0.7); border-bottom: none; color: rgba(255, 255, 255, 0.7); }
</style>
    ';
    $playcode = $this->pdo->quote($playcode);
    $play = $this->pdo->query("SELECT * FROM play WHERE code = $playcode")->fetch();
    // 1 pixel = 1000 caractères
    if (!$max) $width = 'auto';
    else if (is_numeric($max) && $max > 50) $width = round($play['c'] / (100000/$max)).'px';
    else $width = "auto";
    
    
    // requête sur le nombre de caractère d’un rôle dans une scène
    $qsp = $this->pdo->prepare("SELECT sum(c) FROM sp WHERE play = $playcode AND scene = ? AND source = ?");

    echo '
<div class="timebars" style="width: '.$width.'">
    ';
    $actlast = null;
    $list = array();
    foreach ($this->pdo->query("SELECT * FROM scene WHERE play = $playcode") as $scene) {
      $class = '';
      if ($actlast != $scene['act']) $class .= " scene1";
      
      $width = number_format(99*($scene['c']/$play['c']), 1);
      if (!isset($scene['n'])) $scene['n'] = 0+ preg_replace('/\D/', '', $scene['code']);
      echo '  <div class="scene'.$class.'" style="width: '.$width.'%;" title="Acte '.$scene['act'].', scène '.$scene['n'].'">'."\n";
      if ($actlast != $scene['act']) echo '    <b class="act">Acte '.$scene['act'].'</b>';
      $actlast = $scene['act'];
      // boucle sur les rôles en ordre d’importance
      $i = 0;
      foreach ($this->pdo->query("SELECT * FROM role WHERE play = $playcode ORDER BY c DESC") as $role) {
        $qsp->execute(array($scene['code'], $role['code']));
        list($c) = $qsp->fetch();
        $i++;
        if (!$c) continue;
        $height = number_format(100*$c / $scene['c']);
        echo '<div class="role role'.$i.'"';
        echo ' style="height: '.$height.'%"';
        echo ' title="'.$role['label'].', acte '.$scene['act'].', scène '.$scene['n'].', '.round(100*$c / $scene['c']).'%"';
        echo '>';
        if ($width > 5.5 && $height > 15 ) { // && !isset($list[$role['code']])
          echo '<span> '.$role['label'].'</span>';
          $list[$role['code']] = true;
        }
        else echo ' ';
        echo '</div>';
      }
      echo "  </div>\n";
    }
    echo '
</div>
    ';
  }

  /**
   * Table 
   */
  public function timetable($playcode, $max=null) {
    $playcode = $this->pdo->quote($playcode);
    $play = $this->pdo->query("SELECT * FROM play WHERE code = $playcode")->fetch();
    // 1 pixel = 1000 caractères
    if (!$max) $width = '';
    if (is_numeric($max) && $max > 50) $width = ' width="'.round($play['c'] / (100000/$max)).'"';
    else $width = ' width="'.$max.'"';
    
    echo '
<table class="timetable" '.$width.'>
  <caption>'.($this->bibl($play)).'</caption>
';
    // timeline des scènes
    $actlast = null;
    echo '<thead>
  <tr class="scenes">
';
    // attention les pourcentages de la largeur sont comptés sans les noms de personnages
    foreach ($this->pdo->query("SELECT * FROM scene WHERE play = $playcode") as $scene) {
      $class = ' scene';
      if ($actlast != $scene['act']) $class .= " scene1";
      $actlast = $scene['act'];
      $width = number_format(100*($scene['c']/$play['c']), 1);
      $n = 0+ preg_replace('/\D/', '', $scene['code']);
      echo '    <td class="'.$class.'" style="width: '.$width.'%;" title="Acte '.$scene['act'].', scène '.$n.'"/>'."\n";
    }
    echo "  </tr>
  </thead>
";
    
    // requête sur le nombre de caractère d’un rôle dans une scène
    $qsp = $this->pdo->prepare("SELECT sum(c) FROM sp WHERE play = $playcode AND scene = ? AND source = ?");
    // Boucler sur les personnages, un par ligne
    foreach ($this->pdo->query("SELECT * FROM role WHERE play = $playcode ORDER BY c DESC") as $role) {
      echo '  <tr class="'.$role['code'].'">'."\n";
      // boucler sur les scènes
      $label = $role['label'];
      foreach ($this->pdo->query("SELECT * FROM scene WHERE play = $playcode") as $scene) {
        $class = "";
        if ($actlast != $scene['act']) $class .= " scene1";
        $actlast = $scene['act'];
        $qsp->execute(array($scene['code'], $role['code']));
        list($c) = $qsp->fetch();
        $opacity = number_format($c / $scene['c'], 1);
        if (trim($class)) $class = ' class="'.trim($class).'"';
        $n = 0+ preg_replace('/\D/', '', $scene['code']);
        echo '<td'.$class.' style="opacity: '.$opacity.'" title="'.$label.', acte '.$scene['act'].', scène '.$n.', '.round(100*$c / $scene['c']).'%"';
        if (!$c) echo "/>\n";
        else echo "> </td>\n";
      }
      $title='';
      if ($role['title']) $title .= $role['title'].', '.round(100*$role['c'] / $play['c']).'%';
      echo '<th style="position: absolute; " title="'.$title.'">'.$label.'</td>';
      echo '  </tr>'."\n";
    }
    echo '
</table>
';
  }
  /**
   * proprotion de parole par personnage
   */
  public function nodes($play) {
    $play = $this->pdo->quote($play);
    $data = array();
    $data[] = array('Id', 'Label', 'c', 'title', 'targets', 'color');
    foreach ($this->pdo->query("SELECT * FROM role WHERE play = $play ORDER BY c DESC") as $role) {
      if ($role['sex'] == 1 && $role['age'] == 'junior') $color = "#00FFFF";
      else if ($role['sex'] == 1 && $role['age'] == 'veteran') $color = "#0000FF";
      else if ($role['sex'] == 1) $color = "#4080FF";
      else if ($role['sex'] == 2 && $role['age'] == 'junior') $color = "#FFAAFF";
      else if ($role['sex'] == 2 && $role['age'] == 'veteran') $color = "#800000";
      else if ($role['sex'] == 2) $color = "#FF0000";
      else $color = '#CCCCCC';
      $data[] = array(
        $role['code'],
        $role['label'],
        $role['c'],
        ($role['title'])?$role['title']:'',
        $role['targets'],
        $color,
      );
    }
    return $data;
  }
  /**
   * Évolution de la parole selon les personnages
   */
  public function edges($playcode) {
    $play = $this->pdo->query("SELECT * FROM play where code = ".$this->pdo->quote($playcode))->fetch();
    $q = $this->pdo->prepare("SELECT source, target, sum(sp.c) AS ord FROM sp, act WHERE sp.play = ? AND sp.play = act.play AND sp.act = act.code AND act.type = 'act' GROUP BY source, target ORDER BY ord DESC");
    $q->execute(array($playcode));
    $data = array();
    $data[] = array('Source', 'Target', 'Weight', 'max%');
    $max = false;
    $nodes = array();
    while ($sp = $q->fetch()) {
      if(!$max) $max = $sp['ord'];
     
      $dothreshold = true;
      if ($sp['source']==$sp['target']);
      else if (!isset($nodes[$sp['source']])) {
        $nodes[$sp['source']] = 1;
        $dothreshold = false;
      }
      else { 
        $nodes[$sp['source']]++;
      }
      
      if ($sp['source']==$sp['target']);
      else if (!isset($nodes[$sp['target']])) {
        $nodes[$sp['target']] = 1;
        $dothreshold = false;
      }
      else {
        $nodes[$sp['target']]++;
      }
      // a threshold, to apply only on relation already linked to the net
      if ($dothreshold && ( $sp['ord'] <100 || ($sp['ord']/$play['c']) < 0.01) ) {
        continue;
      }
      $data[] = array(
        $sp['source'],
        $sp['target'],
        $sp['ord'],
        number_format($sp['ord']/$play['c'], 2),
      );
    }
    return $data;
  }
  /**
   * Lister les fichiers XML d’un répertoire pour en proposer une table avec quelques métadonnées
   */
  public function dirtable($dir) {
    $dir = rtrim($dir, ' /\\').'/';
    foreach(glob($dir.'*.xml') as $file) {
      $play = $this->play($file);
      echo '
  <tr>
    <td class="author">'.$play['author'].'</td>
    <td class="year">'.$play['year'].'</td>
    <td class="title"><a href="'.basename($file).'">'.$play['title'].'</a></td>
    <td class="genre">'.$play['genre'].'</td>
    <td class="verse">'.(($play['verse'])?"vers":"prose").'</td>
    <td class="acts">'.$play['acts'].'</td>
  </tr>
';
    }
  }
  /**
   * Métadonnées de pièce
   */
  public function play($file) {
    $play = array();
    $this->load($file);
    $play['code'] = pathinfo($file, PATHINFO_FILENAME);
    $nl = $this->_xpath->query("/*/tei:teiHeader//tei:author");
    if ($nl->length) $play['author'] = $nl->item(0)->textContent;
    else $play['author'] = null;
    $nl = $this->_xpath->query("/*/tei:teiHeader/tei:profileDesc/tei:creation/tei:date");
    if ($nl->length) {
      $play['year'] = $nl->item(0)->getAttribute ('when');
      if(!$play['year']) $play['year'] = $nl->item(0)->nodeValue;
      $play['year'] = substr(trim($play['year']), 0, 4);
    }
    else $play['year'] = null;
    $nl = $this->_xpath->query("/*/tei:teiHeader//tei:title");
    if ($nl->length) $play['title'] = $nl->item(0)->textContent;
    else $play['title'] = null;
    $nl = $this->_xpath->query("/*/tei:teiHeader//tei:term[@type='genre']/@subtype");
    if ($nl->length) $play['genre'] = $nl->item(0)->nodeValue;
    else $play['genre'] = null;
    
    $play['acts'] = $this->_xpath->evaluate("count(/*/tei:text/tei:body//tei:*[@type='act'])");
    if (!$play['acts']) $play['acts'] = $this->_xpath->evaluate("count(/*/tei:text/tei:body/*[tei:div|tei:div2])");
    if (!$play['acts']) $play['acts'] = 1;
    $l = $this->_xpath->evaluate("count(//tei:sp/tei:l)");
    $p = $this->_xpath->evaluate("count(//tei:sp/tei:p)");
    if ($l > 2*$p) $play['verse'] = true;
    else if ($p > 2*$l) $play['verse'] = false;
    return $play;
  }
  /**
   * Charger un csv en base
   */
  public function insert($file) {
    $time = microtime(true);
    if (STDERR) fwrite(STDERR, $file);
    $play = $this->play($file);
    $this->pdo->exec("DELETE FROM play WHERE code = ".$this->pdo->quote($play['code']));
    $q = $this->pdo->prepare("
    INSERT INTO play (code, author, title, year, acts, verse, genre)
              VALUES (?,    ?,      ?,     ?,    ?,    ?,     ?);
    ");
    $q->execute(array(
      $play['code'],
      $play['author'],
      $play['title'],
      $play['year'],
      $play['acts'],
      $play['verse'],
      $play['genre'],
    ));
    // roles
    $this->pdo->beginTransaction();
    $q = $this->pdo->prepare("
    INSERT INTO role (play, code, label, title, note, rend, sex, age, status)
              VALUES (?,    ?,    ?,     ?,     ?,    ?,    ?,   ?,   ?);
    ");
    $nodes = $this->_xpath->query("//tei:role");
    $castlist = array();
    foreach ($nodes as $n) {
      $note = null;
      $code = $n->getAttribute ('xml:id');
      if (!$code) continue;
      $castlist[$code] = true;
      $label = $n->getAttribute ('n');
      if (!$label) $label = $n->nodeValue;
      $nl = @$n->parentNode->getElementsByTagName("roleDesc");
      if ($nl->length) $title = trim($nl->item(0)->nodeValue);
      else {
        $title = '';
        $nl = $n->parentNode->firstChild;
        while($nl) {
          if ($nl->nodeType == XML_TEXT_NODE ) $title .= $nl->nodeValue;
          $nl = $nl->nextSibling;
        }
        $title = preg_replace(array("/^[\s :;,\.]+/u", "/[\s :,;\.]+$/u"), array('', ''), $title);
        if (!$title) $title = null;
      }
      $rend = ' '.$n->getAttribute ('rend').' '; // espace séparateur
      if (preg_match('@ female @i', $rend)) $sex = 2;
      else if (preg_match('@ male @i', $rend)) $sex = 1;
      else $sex = null;
      preg_match('@ (cadet|junior|senior|veteran) @i', $rend, $matches);
      if (isset($matches[1]) && $matches[1]) $age = $matches[1];
      else $age = null;
      preg_match('@ (inferior|superior|exterior) @i', $rend, $matches);
      if (isset($matches[1]) && $matches[1]) $status = $matches[1];
      else $status = null;
      if (!trim($rend)) $rend = null;
      // si pas de nom, garder tout de même, risque d’erreur réseau
      if (!$label) $label = $code;
      $q->execute(array(
        $play['code'],
        $code,
        $label,
        $title,
        $note,
        $rend,
        $sex,
        $age,
        $status,
      ));
    }
    $this->pdo->commit();
    if (STDERR) fwrite(STDERR, " play+role: ".number_format(microtime(true) - $time, 3)."s. ");
    $time = microtime(true);
    $xsl = new DOMDocument("1.0", "UTF-8");
    $xsl->load(dirname(__FILE__).'/drama2csv.xsl');
    $this->_xslt->importStyleSheet($xsl);
    $this->_xslt->setParameter('', 'filename', $play['code']);
    $csv = $this->_xslt->transformToXML($this->_dom);
    
    // placer la chaîne dans un stream pour profiter du parseur fgetscsv
    $stream = fopen('php://memory', 'w+');
    fwrite($stream, $csv);
    rewind($stream);
    $inact = $this->pdo->prepare("
    INSERT INTO act (play, code, n, label, type)
            VALUES  (?,    ?,    ?, ?,     ?);
    ");
    $inscene = $this->pdo->prepare("
    INSERT INTO scene (play, act, code, n, label, type)
               VALUES (?,    ?,   ?,    ?, ?,     ?);
    ");
    $insp = $this->pdo->prepare("
    INSERT INTO sp (play, act, scene, code, source, target, l, ln, w, wn, c, cn, text)
            VALUES (?,    ?,   ?,     ?,    ?,      ?,      ?, ?,  ?, ?,  ?, ?,  ?);
    ");
    // première ligne, nom de colonne, à utiliser comme clé pour la collecte des lignes 
    $keys = fgetcsv($stream, 0, "\t");
    // boucle pour charger la base, ne pas oublier de démarrer une transaction
    $this->pdo->beginTransaction();
    $wn = 1;
    $cn = 1;
    $act = null;
    $scene= null;
    while (($values = fgetcsv($stream, 0, "\t")) !== FALSE) {
      // étiqueter les cases avec les noms de colonne de la première ligne, les deux tableaux combinés doivent avoir la même taille
      $data = array_combine($keys, array_merge($values, array_fill(0, count($keys) - count($values), null)));
      // réplique
      if ($data['object'] == 'sp' ) {
        if (!isset($castlist[$data['source']]) && STDERR) fwrite(STDERR, "@who ERROR ".$data['source']. " [".$data['code']."]\n");
        if (!$data['c']) {
          if (STDERR) fwrite(STDERR, "Empty <sp> [".$data['code']."]\n");
          continue;
        }
        if (!$data['l']) $data['l'] = null;
        if (!$data['ln']) $data['ln'] = null;
        try {
          $insp->execute(array(
            $play['code'],
            $act, 
            $scene, 
            $data['code'],
            $data['source'],
            $data['target'],
            $data['l'],
            $data['ln'], 
            $data['w'], // w
            $wn,
            $data['c'], // c
            $cn,
            $data['text'], // text
          ));
          $wn = $wn + $data['w'];
          $cn = $cn + $data['c'];
        }
        catch (Exception $e) {
          if (STDERR) fwrite(STDERR, "\n\n      NOT UNIQUE <sp> ? ".$data['code']."\n".$e."\n\n");
        }
      }
      // scènes, parfois non contenues dans un acte (pièces en un acte)
      else if ($data['object'] == 'div2' || $data['type'] == 'scene' ) {
        $scene = $data['code'];
        if (!$data['type']) $data['type'] = null; 
        try {
          $inscene->execute(array(
            $play['code'],
            $act,
            $data['code'],
            $data['n'],
            $data['label'],
            $data['type'],
          ));
        }
        catch (Exception $e) {
          if (STDERR) fwrite(STDERR, "\n\n      NOT UNIQUE scene ? ".$data['code']."\n".$e."\n\n");
        }
      }
      // actes
      else if ($data['object'] == 'div1' ) {
        $scene = null;
        $act = $data['code'];
        if(!$data['type']) $data['type'] = 'act';
        try {
          $inact->execute(array(
            $play['code'],
            $data['code'],
            $data['n'],
            $data['label'],
            $data['type'],
          ));
        }
        catch (Exception $e) {
          if (STDERR) fwrite(STDERR, "\n\n      NOT UNIQUE act ? ".$data['code']."\n".$e."\n\n");
        }
      }
      
    }
    $this->pdo->commit();
    // différentes stats prédef
    $play = $this->pdo->quote($play['code']);
    if (STDERR) fwrite(STDERR, " sp: ".number_format(microtime(true) - $time, 3)."s. ");
    $time = microtime(true);
    $this->pdo->beginTransaction();
    $this->pdo->exec("UPDATE play SET sp = (SELECT COUNT(*) FROM sp WHERE play = $play) WHERE code = $play;");
    $this->pdo->exec("UPDATE play SET l = (SELECT SUM(l) FROM sp WHERE play = $play) WHERE code = $play;");
    $this->pdo->exec("UPDATE play SET w = (SELECT SUM(w) FROM sp WHERE play = $play) WHERE code = $play;");
    $this->pdo->exec("UPDATE play SET c = (SELECT SUM(c) FROM sp WHERE play = $play) WHERE code = $play;");
    $this->pdo->exec("UPDATE play SET scenes = (SELECT COUNT(*) FROM scene WHERE play = $play) WHERE code = $play;");
    
    $this->pdo->exec("UPDATE act SET sp = (SELECT COUNT(*) FROM sp WHERE play = $play AND sp.act = act.code) WHERE play = $play;");
    $this->pdo->exec("UPDATE act SET l = (SELECT SUM(l) FROM sp WHERE play = $play AND sp.act = act.code) WHERE play = $play;");
    $this->pdo->exec("UPDATE act SET ln = (SELECT ln FROM sp WHERE play = $play AND sp.act = act.code ORDER BY rowid LIMIT 1) WHERE play = $play;");
    $this->pdo->exec("UPDATE act SET w = (SELECT SUM(w) FROM sp WHERE play = $play AND sp.act = act.code) WHERE play = $play;");
    $this->pdo->exec("UPDATE act SET wn = (SELECT wn FROM sp WHERE play = $play AND sp.act = act.code ORDER BY rowid LIMIT 1) WHERE play = $play;");
    $this->pdo->exec("UPDATE act SET c = (SELECT SUM(c) FROM sp WHERE play = $play AND sp.act = act.code) WHERE play = $play;");
    $this->pdo->exec("UPDATE act SET cn = (SELECT cn FROM sp WHERE play = $play AND sp.act = act.code ORDER BY rowid LIMIT 1) WHERE play = $play;");
    
    $this->pdo->exec("UPDATE scene SET sp = (SELECT COUNT(*) FROM sp WHERE play = $play AND sp.scene = scene.code) WHERE play = $play;");
    $this->pdo->exec("UPDATE scene SET l = (SELECT SUM(l) FROM sp WHERE play = $play AND sp.scene = scene.code) WHERE play = $play;");
    $this->pdo->exec("UPDATE scene SET ln = (SELECT ln FROM sp WHERE play = $play AND sp.scene = scene.code ORDER BY rowid LIMIT 1) WHERE play = $play;");
    $this->pdo->exec("UPDATE scene SET w = (SELECT SUM(w) FROM sp WHERE play = $play AND sp.scene = scene.code) WHERE play = $play;");
    $this->pdo->exec("UPDATE scene SET wn = (SELECT wn FROM sp WHERE play = $play AND sp.scene = scene.code ORDER BY rowid LIMIT 1) WHERE play = $play;");
    $this->pdo->exec("UPDATE scene SET c = (SELECT SUM(c) FROM sp WHERE play = $play AND sp.scene = scene.code) WHERE play = $play;");
    $this->pdo->exec("UPDATE scene SET cn = (SELECT cn FROM sp WHERE play = $play AND sp.scene = scene.code ORDER BY rowid LIMIT 1) WHERE play = $play;");
    
    $this->pdo->exec("UPDATE role SET targets = (SELECT COUNT(DISTINCT target) FROM sp WHERE play = $play AND source = role.code) WHERE play = $play;");
    $this->pdo->exec("UPDATE role SET sp = (SELECT COUNT(*) FROM sp WHERE play = $play AND sp.source = role.code) WHERE play = $play;");
    $this->pdo->exec("UPDATE role SET l = (SELECT SUM(l) FROM sp WHERE play = $play AND sp.source = role.code) WHERE play = $play;");
    $this->pdo->exec("UPDATE role SET w = (SELECT SUM(w) FROM sp WHERE play = $play AND sp.source = role.code) WHERE play = $play;");
    $this->pdo->exec("UPDATE role SET c = (SELECT SUM(c) FROM sp WHERE play = $play AND sp.source = role.code) WHERE play = $play;");
    $this->pdo->commit();
    if (STDERR) fwrite(STDERR, " stats: ".number_format(microtime(true) - $time, 3)."s.\n");
  }
  /**
   * Collecter les identifiants dans les <role>
   * Alerter sur les identifiants inconnus
   */
  public function valid($file) {
    $this->load($file);
    $nodes = $this->_xpath->query("//tei:role/@xml:id");
    $castlist = array();
    foreach ($nodes as $n) {
      $castlist[$n->nodeValue] = true;
    }
    $nodes = $this->_xpath->query("//@who");
    foreach ($nodes as $n) {
      $who = $n->nodeValue;
      if (isset($castlist[$who])) continue;
      if (STDERR) fwrite(STDERR, $who.' l. '.$n->getLineNo()."\n");
    }
  }

  /**
   * Command line API 
   */
  static function cli() {
    $timeStart = microtime(true);
    $usage = '
    usage    : php -f '.basename(__FILE__).' base.sqlite {action} {arguments}
    where action can be
    valid  ../*.xml
    insert ../*.xml
    gephi playcode 
';
    $timeStart = microtime(true);
    array_shift($_SERVER['argv']); // shift first arg, the script filepath
    if (!count($_SERVER['argv'])) exit($usage);
    $sqlite = array_shift($_SERVER['argv']);
    $base = new Dramaturgie_Base($sqlite);
    if (!count($_SERVER['argv'])) exit('
    action  ? (valid|insert|gephi)
');
    $action = array_shift($_SERVER['argv']);
    if ($action == 'insert') {
      if (!count($_SERVER['argv'])) exit('
    insert requires a file or a glob expression to insert XML/TEI play file
');
      $file = array_shift($_SERVER['argv']);
      if (file_exists($file)) {
        $base->insert($file);
      }
      else {
        $glob = $file;
        foreach(glob($glob) as $file) {
          // spécifique Molière
          if (preg_match('@-livret\.@', $file)) continue;
          $base->insert($file);
        }
      }
      // TODO, glob
    }
    if ($action == 'gephi') {
      $base->gephi(array_shift($_SERVER['argv']));
    }
  }
}
?>