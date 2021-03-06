<?php
defined('C5_EXECUTE') or die("Access Denied.");

//Permissions Check
if($_GET['bID']) {
	$c = Page::getByID($_GET['cID']);
	$a = Area::get($c, $_GET['arHandle']);
		
	//edit survey mode
	$b = Block::getByID($_GET['bID'],$c, $a);
	
	$controller = new PageListBlockController($b);
	$rssUrl = $controller->getRssUrl($b);
	
	$bp = new Permissions($b);
	if( $bp->canRead() && $controller->rss && ($b->getBlockFilename() == 'blog_index.php' || $b->getBlockFilename() == 'blog_index')) {

		$cArray = $controller->getPages();
		$nh = Loader::helper('navigation');

		header('Content-type: text/xml');
		echo "<" . "?" . "xml version=\"1.0\"?>\n";

?>
		<rss version="2.0">
		  <channel>
			<title><?=$controller->rssTitle?></title>
			<link><?=BASE_URL.$rssUrl?></link>
			<description><?=$controller->rssDescription?></description> 
<?
		for ($i = 0; $i < count($cArray); $i++ ) {
			$cobj = $cArray[$i]; 
			$title = $cobj->getCollectionName(); ?>
			<item>
			  <title><?=htmlspecialchars($title);?></title>
			  <link>
				<?= BASE_URL.DIR_REL.$nh->getLinkToCollection($cobj) ?>		  
			  </link>
			  <description><![CDATA[
				<?php
				$a = new Area('Main');
				$a->disableControls();
				$a->display($cobj);
				?>
			  ]]></description>
			  <? /* <pubDate><?=$cobj->getCollectionDatePublic()?></pubDate>
			  Wed, 23 Feb 2005 16:12:56 GMT  */ ?>
			  <pubDate><?=date( 'D, d M Y H:i:s T',strtotime($cobj->getCollectionDatePublic())) ?></pubDate>
			</item>
		<? } ?>
     		 </channel>
		</rss>
		
<?	} else {  	
		$v = View::getInstance();
		$v->renderError('Permission Denied',"This page list doesn't use the custom blog template, or you don't have permission to access this RSS feed");
		exit;
	}
			
} else {
	echo "You don't have permission to access this RSS feed";
}
exit;






