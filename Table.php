<?php
/**
 * Visualisation relatives au réseaux de parole
 */
class Dramagraph_Table
{
  /**
   * Table des relations
   */
  public static function relations ( $pdo, $playcode )
  {
    $play = $pdo->query("SELECT * FROM play where code = ".$pdo->quote($playcode))->fetch();
    // récupérer la distribution, pour avoir les noms de personnage
    $cast = array();
    foreach  ($pdo->query("SELECT id, code, label, c FROM role WHERE play = ".$play['id'], PDO::FETCH_ASSOC) as $row) {
      $cast[$row['id']] = $row;
    }
    $html = array();
    $html[] = '
<table class="sortable" align="center">
  <caption>Table des relations</caption>
  <tr>
    <th title="Nombre de scènes avec les deux personnages en présence">Scènes</th>
    <th title="Quantité de texte de la relation">Texte</th>
    <th title="Part de la relation dans le texte">% texte</th>
    <th title="Nom de personnage">Pers. 1</th>
    <th title="Taille moyenne des répliques du personnage dans la relation en lignes (60 signes))">Repl. moy.</th>
    <th title="Part de ce personnage dans la relation">%</th>
    <th title="Nom de personnage">Pers. 2</th>
    <th title="Taille moyenne des répliques du personnage dans la relation en lignes (60 signes))">Repl. moy.</th>
    <th title="Part de ce personnage dans la relation">%</th>
  </tr>';
    // $pdo->query("SELECT * FROM play where code = ".$pdo->quote($playcode))->fetch();
    $sql = "SELECT
        min (source, target) AS m1,
        max( source, target ) AS m2,
        edge.source,
        edge.target,
        count(sp) AS sp,
        sum(sp.c) AS c,
        count(DISTINCT configuration) AS confs
      FROM edge, sp
      WHERE edge.sp = sp.id AND edge.play = ".$play['id']."
      GROUP BY edge.source, edge.target
      ORDER BY m1, m2
    ";
    $m1 = null;
    $m1c = 0;
    $m1sp = 0;
    $m2 = null;
    $m2c = 0;
    $m2sp = 0;
    $c = 0;
    $confs = 0;

    foreach ( $pdo->query( $sql, PDO::FETCH_ASSOC ) as $row ) {
      // $m1 != null && $m2 != null &&
      // 2e ligne
      if ( $m1 == $row['m1'] && $m2 == $row['m2'] ) {
        $c = $c + $row['c'];
        if ( $row['confs'] > $confs ) $confs = $row['confs'];
        if ( $row['source'] == $m1 ) {
          $m1c = $row['c'];
          $m1sp = $row['sp'];
        }
        else if ( $row['source'] == $m2 ) {
          $m2c = $row['c'];
          $m2sp = $row['sp'];
        }
        $html[] = '<tr>
  <td align="right">'.$confs.'</td>
  <td align="right">'.ceil( $c/60 ).' l.</td>
  <td align="right">'.ceil( 100 * $c / $play['c'] ).' %</td>
  <td>'.$cast[$m1]['label'].'</td>
  <td align="right">'.number_format( $m1c/$m1sp/60 , 1, ',', ' ').' l.</td>
  <td align="right">'.ceil( 100 * $m1c / $c ).' %</td>
  <td>'.$cast[$m2]['label'].'</td>
  <td align="right">'.number_format( $m2c/$m2sp/60 , 1, ',', ' ').' l.</td>
  <td align="right">'.ceil( 100 * $m2c / $c ).' %</td>
</tr>';
        $m1 = null;
        $m2 = null;
        $confs = 0;
      }
      // monologue
      if ( $row['m1'] == $row['m2'] ) {
        $html[] = '<tr>
  <td align="right">'.$row['confs'].'</td>
  <td align="right">'.ceil( $row['c']/60 ).' l.</td>
  <td align="right">'.ceil( 100 * $row['c'] / $play['c'] ).' %</td>
  <td>'.$cast[$row['m1']]['label'].'</td>
  <td align="right">'.number_format( $row['c']/60/$row['sp'] , 1, ',', ' ').' l.</td>
  <td align="right">100 %</td>
  <td>'.$cast[$row['m1']]['label'].'</td>
  <td align="right">'.number_format( $row['c']/60/$row['sp'] , 1, ',', ' ').' l.</td>
  <td align="right">100 %</td>
</tr>';
        $c = 0;
        $m1 = null;
        $m2 = null;
        $confs = 0;
      }
      // autre ligne
      else {
        $c = $row['c'];
        $m1 = $row['m1'];
        $m2 = $row['m2'];
        if ( $row['source'] == $m1 ) {
          $m1c = $row['c'];
          $m1sp = $row['sp'];
        }
        else if ( $row['source'] == $m2 ) {
          $m2c = $row['c'];
          $m2sp = $row['sp'];
        }
        $confs = $row['confs'];
      }
    }
    $html[] = "</table>";
    return implode("\n", $html);
  }
  /**
   * Table des rôles
   */
  public static function roles ($pdo, $playcode)
  {
    $play = $pdo->query("SELECT * FROM play where code = ".$pdo->quote($playcode))->fetch();
    $html = array();
    $html[] = '<table class="sortable" align="center">';
    $html[] = '  <caption>Table des rôles</caption>';
    $html[] = '  <tr>';
    $html[] = '    <th title="Nom du personnage dans l’ordre de la distribution">Personnage</th>';
    $html[] = '    <th title="Quantité de texte du personnage en lignes (60 signes)">Texte</th>';
    $html[] = '    <th title="Part du personnager dans le texte de la pièce">% texte</th>';
    $html[] = '    <th title="Taille moyenne des répliques du personnage, en lignes (60 signes)">Répl. moy.</th>';
    // $html[] = '    <th title="Nombre de rôles interagissant avec le personnage">Interl.</th>';
    $html[] = '    <th title="Nombre de scènes où le personnage est présent">Scènes</th>';
    $html[] = '    <th title="Présence du personnage en proportion du texte dit de la pièce">Prés.</th>';
    $html[] = '    <th title="Part du texte que le personnage prononce, durant son temps de présence">Txt. % prés.</th>';
    // $html[] = '    <th title="Nombre de répliques du personnages">Répl.</th>';
    $html[] = '    <th title="Nombre moyen de personnages parlants sur scène au moment où le personnage est présent">Occupation</th>';
    $html[] = '  </tr>';
    $html[] = '  <tr>';
    $html[] = '    <td data-sort="0">[TOUS]</td>';
    $html[] = '    <td align="right">'.number_format($play['c']/60, 0, ',', ' ').' l.</td>';
    $html[] = '    <td align="right">100 %</td>';
    $html[] = '    <td align="right">'.number_format($play['c']/($play['sp']*60), 1, ',', ' ').' l.</td>';
    // $html[] = '    <td align="right" title="Nombre total de personnages">'.$play['roles'].'</td>';
    $html[] = '    <td align="right">'.$play['confs'].'</td>';
    $html[] = '    <td align="right">100 %</td>';
    // $html[] = '    <td align="right">'.number_format($play['entries']/$play['roles'], 1, ',', ' ').'</td>';
    $html[] = '    <td align="right">'.ceil(100 * $play['c']/$play['pspeakers'])." %</td>";
    // $html[] = '    <td align="right">'.$play['sp'].'</td>';
    $html[] = '    <td align="right">'.number_format($play['pspeakers']/$play['c'], 1, ',', ' ').' pers.</td>';
    $html[] = '  </tr>';
    $i = 1;
    foreach ($pdo->query("SELECT * FROM role WHERE role.play = ".$play['id']." ORDER BY ord") as $role) {
      $html[] = "  <tr>";
      $html[] = '    <td data-sort="'.$i.'" title="'.$role['title'].'">'.$role['label']."</td>";
      $html[] = '    <td align="right">'.number_format($role['c']/60, 0, ',', ' ').' l.</td>';
      $html[] = '    <td align="right">'.ceil(100 * $role['c']/$play['c'])." %</td>";
      if ($role['sp']) $html[] = '    <td align="right">'.number_format($role['c']/($role['sp']*60), 1, ',', ' ')." l.</td>";
      else $html[] = '<td align="right">0</td>';

      // $html[] = '    <td align="right">'.$role['targets']."</td>";
      /*
      */
      $html[] = '    <td align="right">'.$role['confs'].'</td>';
      $html[] = '    <td align="right">'.ceil(100 * $role['presence']/$play['c'])." %</td>";
      // $html[] = '    <td align="right">'.$role['entries'].'</td>';
      if ($role['presence']) $html[] = '    <td align="right">'.ceil( 100 * $role['c']/$role['presence'])." %</td>";
      else $html[] = '    <td align="right">0</td>';
      // $html[] = '    <td align="right">'.$role['sp']."</td>";
      // echo '    <td align="right">'.$node['ic']."</td>\n";
      // echo '    <td align="right">'.$node['isp']."</td>\n";
      // echo '    <td align="right">'.round($node['ic']/$node['isp'])."</td>\n";
      if ($role['presence']) $html[] = '    <td align="right">'.number_format($role['pspeakers']/$role['presence'], 1, ',', ' ').' pers.</td>';
      else $html[] = '    <td align="right">0</td>';
      $html[] = "  </tr>";
      $i++;
    }
    $html[] = '</table>';
    return implode("\n", $html);
  }

}

 ?>
