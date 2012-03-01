<?php
class Link {
}
class Asset {
	var $container;
	var $children = array();
	var $errors = array();
	var $fixes = array();
	function __construct($assoc,$container) {
		$this->container = $container;
		foreach ($assoc as $key => $val) {
			$this->$key = $val;
		}
	}
	function addChild($asset) {
		$this->children[] = $asset;
		$this->sortChildren();
	}
	function setParent($parent) {
		if (isset($this->parent_asset)) { $this->parent_asset->removeChild($this); }
		$this->parent_asset = $parent;
		$this->parent_id = $this->parent_asset->id;
		$this->parent_asset->addChild($this);
	}
	function removeChild($asset) {
		$ch = array();
		foreach ($this->children as $child) {
			if ($child->id != $asset->id) {
				$ch[] = $child;
			}
		}
		$this->children = $ch;
	}
	function sortChildren() {
		$ref = array();
		foreach ($this->children as $child) {
			$ref[$child->lft][] = $child;
		}
		ksort($ref);
		$nc = array();
		foreach ($ref as $key => $childs) {
			if (count($childs) > 1) {
				$this->container->addError('Duplicate lft values',$childs);
			}
			foreach ($childs as $child) {
				$nc[] = $child;
			}
		}
		$this->children = $nc;
	}
	function printOut() {
		print ' '.$this->id.' (̈́lvl'.$this->level.') (ch'.count($this->children).') ';
		print $this->lft.' '.$this->rgt.' ';
		print '['.substr($this->name,0,30).'] '.substr($this->title,0,40);
	}
	function printReport() {
		$asset = $this;
		print "\n".'* Report for asset '.$asset->id.":\n";
		$asset->printOut();
		print "\n";
		foreach ($asset->errors as $error) {
			print "Error: ".$error->msg."\n";
		}
		foreach ($asset->fixes as $fix) {
			print "Fix: $fix\n";
		}
	}
	function printStructure($depth = 0,$limitdepth=false,$limittype=false,$allowchildren=false) {
		if (($limitdepth) && ($depth > $limitdepth)) return;
		$skip = false;
		if ($limittype) {
			$skip = true;
			foreach ($limittype as $limit) {
				if (strpos($this->name,$limit) !== false) $skip = false;
			}
			if (($skip) && (!$allowchildren)) return;
		}
		if (!$skip) {
			if (isset($this->parent_asset)) {
				$len = strlen($this->parent_asset->id);
				print $this->parent_asset->id;
				for ($i=$len; $i<4; $i++) print ' ';
			}
			for ($i=0; $i<($depth*2); $i++) print '.';
			
			$this->printOut();
			
			if ($this->errors) {
				print ' (';
				foreach ($this->errors as $error) {
					print '!';
				}
				print ')';
			}
			foreach ($this->fixes as $fix) {
				print '*';
			}
			print "\n";
		}
		foreach ($this->children as $key => $ch) {
			$ch->printStructure($depth+1,$limitdepth,$limittype,$allowchildren);
		}
	}
	function checkTree($cnum,$depth=0) {
		// Check depth
		if ($depth != $this->level) {
			$this->container->addError('Depth level not what expected (exp '.$depth.' is '.$this->level.')',$this);
		}

		// First check if left side matches
		if ($cnum) {
			if ($cnum != $this->lft) {
				$this->container->addError('LFT value not what expected by parent (exp '.$cnum.' is '.$this->lft.')',$this);
			}
		}
		$cnum = $this->lft;
		// Check children
		foreach ($this->children as $child) {
			$cnum = $child->checkTree($cnum+1,$depth+1);
		}
		// Increase, and check right side
		$cnum++;
		if ($cnum != $this->rgt) {
			$this->container->addError('RGT value not what expected (exp '.$cnum.' is '.$this->rgt.')',$this);
		}
		// Return right side number
		return $cnum;
	}
	function reIndex($cval=1,$level=0) {
		// CVAL is the LFT of parent element
		$cval++;
		$lft = $cval;
		foreach ($this->children as $child) {
			$cval = $child->reIndex($cval,$level+1);
		}
		$cval++;
		$rgt = $cval;
		if (($this->lft != $lft) || ($this->rgt != $rgt) || ($this->level != $level)) {
			$this->fixes[] = 'Changing LFT('.$this->lft.'->'.$lft.'),RGT('.$this->rgt.'->'.$rgt.'),LEVEL('.$this->level.'->'.$level.') of asset at reindex.';
			$this->container->fixed[] = $this;
		}
		$this->lft = $lft;
		$this->rgt = $rgt;
		$this->level = $level;
		print '.';
		// return value is RGT of child element
		return $cval;
	}
}

class AssetError {
	var $assets = array();
	var $msg;
	var $silent;
	function __construct($msg,$assets=null,$silent=false) {
		$this->silent = $silent;
		$this->msg = $msg;
		if ($assets) {
			if (!is_array($assets)) {
				$assets = array($assets);
			}
			$this->assets = $assets;
			foreach ($assets as $asset) {
				$asset->errors[] = $this;
			}
		}
	}
}

class AssetContainer {
	var $assets; 
	var $roots = null;
	var $errors = array();
	var $fixed = array();
	var $extrasql = array();
	var $reindexrequired = false;
	
	function newAsset($assoc) {
		$na = new Asset($assoc,$this);
		$this->assets[$na->id] = $na;
		if ($na->name == 'root.1') {
			$this->trueroot = $na;
		}
		unset($na);
	}
	function assetArticleLink($assoc) {
		$aid = $assoc['asset_id'];
		if (isset($this->assets[$aid])) {
			$this->assets[$aid]->article = new Link();
			foreach ($assoc as $key => $val) {
				$this->assets[$aid]->article->$key = $val;
			}
		} else { $this->addError("Asset ($aid) not found for article (".$assoc['id'].")"); }
	}
	function assetCategoryLink($assoc) {
		$aid = $assoc['asset_id'];

		// Find root category
		if (!isset($this->assets[$aid])) {
			if (($assoc['level'] == 0) && ($assoc['title'] == 'ROOT')) {
				$this->rootcategoryid = $assoc['id'];
			}
		}
		
		if (isset($this->assets[$aid])) {
			$this->assets[$aid]->category = new Link();
			foreach ($assoc as $key => $val) {
				$this->assets[$aid]->category->$key = $val;
			}
		} elseif ($assoc['id'] != $this->rootcategoryid) { // Root category has no asset
			$this->addError("Asset ($aid) not found for category (".$assoc['id'].")");
		}
	}
	function linkAssets() {
		foreach ($this->assets as $id => $asset) {
			if (isset($this->assets[$asset->parent_id])) {
				$asset->parent_asset = $this->assets[$asset->parent_id];
				$asset->parent_asset->addChild($asset);
			} else {
				$this->setRoot($asset);
			}
		}
		if (count($this->roots) > 1) $this->attemptAssetNameStructureFix();
		//if (count($this->roots) > 1) $this->attemptDirectLinkFix();
		if (count($this->roots) > 1) $this->attemptWideLinkFix();
		$this->attemptArticleLinkFix();
		if ($this->reindexrequired) {
			$this->fullReIndex();
		}
	}
	function removeRoot($assetid) {
		$nr = array();
		foreach ($this->roots as $root) {
			if ($root->id != $assetid) {
				$nr[] = $root;
			}
		}
		$this->roots = $nr;
	}
	function attemptAssetNameStructureFix() {
		print "Attempting to link assets by name structure: ";
		$assetref = array();
		foreach ($this->assets as $asset) {
			// Create asset name reference
			$assetref[$asset->name] = $asset;
		}
		foreach ($this->assets as $asset) {
			print '.';
			if (count($asset->errors)>0) {
				$parts = explode('.',$asset->name);
				while (count($parts)>1) {
					array_pop($parts);
					$linkname = join('.',$parts);
					if (isset($assetref[$linkname])) {
						$asset->setParent($assetref[$linkname]);
						$asset->fixes[] = 'Parent assigned by attemptAssetNameStructureFix';
						$this->removeRoot($asset->id);
						foreach ($asset->errors as $error) { $error->silent = true; }
						$this->fixed[] = $asset;
						$this->reindexrequired = true;
					}
				}
			}
		}
		print " done.\n";
	}
	function attemptDirectLinkFix() {
		// Attempt a fix by looking for one above parentless asset's lft value 
		$ref = array();
		foreach ($this->assets as $id => $asset) {
			$ref[$asset->lft] = $asset;
		}
		foreach ($this->assets as $id => $asset) {
			if ((!isset($asset->parent_asset)) && ($asset->errors)) {
				if (isset($ref[($asset->lft-1)])) {
					$asset->parent_asset = $ref[($asset->lft-1)];
					$asset->parent_asset->addChild($asset);
					$asset->fixes[] = 'Parent assigned by attemptDirectLinkFix';
					$this->removeRoot($asset->id);
					$this->fixed[] = $asset;
				}
			}
		}
	}
	function attemptWideLinkFix() {
		// Attempt a fix for parentless assets by looking for lft-rgt ranges
		$ref = array();
		foreach ($this->assets as $id => $asset) {
			$lft = $asset->lft;
			$rgt = $asset->rgt;
			if ($rgt > $lft) {
				for ($i=($lft+1); $i<=$rgt; $i++) {
					$ref[$i][] = $asset;
				}
			}
		}
		foreach ($this->assets as $id => $asset) {
			if ((!isset($asset->parent_asset)) && ($asset->errors)) {
				if (isset($ref[$asset->lft])) {
					$widths = array();
					foreach ($ref[$asset->lft] as $tgt) {
						$wdt = $tgt->rgt - $tgt->lft;
						$widths[$wdt][] = $asset;
					}
					ksort($widths);
					$sel = array_shift($widths);
					$sel = $sel[0]; // If width is same, just go by random
					$asset->parent_asset = $sel[0];
					$asset->parent_asset->addChild($asset);
					$asset->fixes[] = 'Parent assigned by attemptWideLinkFix';
					$this->removeRoot($asset->id);
					$this->fixed[] = $asset;
				} else {
//					print 'No ref for '.$asset->lft."\n"; if (@$ccc++ > 5) die();
				}
			}
		}
				
	
	}
	
	function attemptArticleLinkFix() {
		print "Attempting to fix article and category assets based on article category structure\n";
		// Create category refefence
		$catref = array();
		$rootcat = null;
		$topcontent = $this->trueroot; // If no content top asset, use root *sigh*
		$assetref = array();
		foreach ($this->assets as $asset) {
			// Find root category
			if (isset($asset->category)) {
				$catref[$asset->category->id] = $asset;
				if ($asset->category->title == 'ROOT') {
					if ($rootcat) {
						$this->error('Duplicate root categories',array($asset,$rootcat));
					} else {
						$rootcat = $asset;
					}
				}
			}
			// Find com_content asset
			if ($asset->name == 'com_content') {
				$topcontent = $asset;
			}
		}
		
		// Link under category asset, where possible
		// NOTE: after this lft,rgt of both the asset and it's new parent are seriously screwed up
		print "Linking articles and categories ";
		foreach ($this->assets as $asset) {
			if (isset($asset->article)) {
				if (!isset($catref[$asset->article->catid])) {
					$this->addError('Asset for article ('.$asset->name.') category ('.$asset->article->catid.') not found',$asset,true);
					$asset->fixes[] = 'Article has no category, setting asset parent as root';
					$parent = $this->roots[0];
				} else {
					$parent = $catref[$asset->article->catid];
				}
				if ($asset->parent_id != $parent->id) {
					print '.';
					$this->addError('Asset parent ('.$asset->parent_id.') different from article category asset ('.$parent->id.')',$asset);
					$asset->setParent($parent);
					$asset->fixes[] = 'Parent assigned by attemptArticleLinkFix';

					$this->removeRoot($asset->id);
					foreach ($asset->errors as $error) { $error->silent = true; }
					$this->fixed[] = $asset;
					$this->reindexrequired = true;		
				} // ELSE already has correct parent
			}
			if (isset($asset->category)) {
				if (isset($catref[$asset->category->parent_id])) {
					$parent = $catref[$asset->category->parent_id];
					if ($asset->parent_id != $parent->id) {
						$this->addError('Asset parent ('.$asset->parent_id.') different from category parent asset ('.$parent->id.')',$asset);
						$asset->setParent($parent);
						$asset->fixes[] = 'Parent assigned by attemptArticleLinkFix';

						$this->removeRoot($asset->id);
						foreach ($asset->errors as $error) { $error->silent = true; }
						$this->fixed[] = $asset;
						$this->reindexrequired = true;		
					} // ELSE already has correct parent
				} else {
					if ($asset->category->parent_id != $this->rootcategoryid) {
						$this->addError('Asset for category parent ('.$asset->category->parent_id.') not found',$asset);
					} // ELSE root category doesn't need an asset, so no problem
				}
			}
		}
		print "done.\n";

	}
	function fullReIndex() {
		print "Reindexing ";
		
		$cval = 0;
		foreach ($this->roots as $root) {
			$cval++;
			$cval = $root->reIndex($cval);
		}
		
		print "done.\n";
	}
	function setRoot($asset) {
		if ($this->roots) {
			if ((count($this->roots) == 1)) {
				$this->addError('Multiple root objects',$this->roots[0]);
			}
			$this->roots[] = $asset;
			$this->addError('Root object already exists and asset without parent',$asset,true);
		} else {
			$this->roots = array($asset);
		}
	}
	function addError($msg,$asset=null,$silent=false) {
		$this->errors[] = new AssetError($msg,$asset,$silent);
	}
	function checkConsistency() {
		// Check root
		if (!$this->roots) {
			$this->addError('No root object');
			return;
		}
		// Check traversal
		foreach ($this->roots as $root) {
			$root->checkTree(null);
		}
		foreach ($this->assets as $asset) {
			if ($asset->rgt <= $asset->lft) {
				$this->addError('LFT ('.$asset->lft.') and RGT ('.$asset->rgt.') values not proper',$asset);
			}
		}
	}
	function printTree() {
		foreach ($this->roots as $i => $root) {
			print 'Tree '.($i+1).' / '.count($this->roots)."\n";
			$filter = array('user','root');
			$filter = false;
			$root->printStructure(0,false,$filter,true);
		}
	}
	function countActiveErrors() {
		$count = 0;
		foreach ($this->errors as $error) {
			if (!$error->silent) {
				$count++;
			}
		}
		return $count;
	}
	function printErrors($showall=false) {
		foreach ($this->errors as $error) {
			if ((!$error->silent) || ($showall)) {
				foreach ($error->assets as $asset) {
					print '{'.$asset->id.'} ';
				}
				print $error->msg;
				print "\n";
				if (@$runner++ > 10) { print "\nBreak!\n"; break; }
			}
		}
	}
	function printFixes() {
		foreach ($this->fixed as $asset) {
			$asset->printReport();
		}
	}
	function generateSQL($prefix) {
		$sqls = array();
		foreach ($this->assets as $asset) {
			if ($asset->fixes) {
				$sqls[] = "UPDATE $prefix"."assets SET parent_id = ".$asset->parent_id.",lft = ".$asset->lft.",rgt = ".$asset->rgt.",level = ".$asset->level." WHERE id = ".$asset->id;
			}
		}
		foreach ($this->extrasql as $psql) {
			$sqls[] = str_replace('%prefix%',$prefix,$psql);
		}
		return $sqls;
	}
}

