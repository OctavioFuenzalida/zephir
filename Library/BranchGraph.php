<?php

/*
 +--------------------------------------------------------------------------+
 | Zephir Language                                                          |
 +--------------------------------------------------------------------------+
 | Copyright (c) 2013-2014 Zephir Team and contributors                     |
 +--------------------------------------------------------------------------+
 | This source file is subject the MIT license, that is bundled with        |
 | this package in the file LICENSE, and is available through the           |
 | world-wide-web at the following url:                                     |
 | http://zephir-lang.com/license.html                                      |
 |                                                                          |
 | If you did not receive a copy of the MIT license and are unable          |
 | to obtain it through the world-wide-web, please send a note to           |
 | license@zephir-lang.com so we can mail you a copy immediately.           |
 +--------------------------------------------------------------------------+
*/

/**
 * BranchGraph
 *
 * Represents a group of branch nodes
 */
class BranchGraph
{
	protected $_root;

	protected $_branchMap;

	/**
	 * Adds a leaf to the branch tree
	 *
	 * @param Branch $branch
	 */
	public function addLeaf(Branch $branch)
	{

		if (isset($this->_branchMap[$branch->getUniqueId()])) {
			$branchNode = $this->_branchMap[$branch->getUniqueId()];
		} else {
			$branchNode = new BranchGraphNode($branch);
		}
		$branchNode->increase();

		$tempBranch = $branch->getParentBranch();
		while ($tempBranch) {
			if (isset($this->_branchMap[$tempBranch->getUniqueId()])) {
				$parentBranchNode = $this->_branchMap[$tempBranch->getUniqueId()];
			} else {
				$parentBranchNode = new BranchGraphNode($tempBranch);
				$this->_branchMap[$tempBranch->getUniqueId()] = $parentBranchNode;
			}
			$parentBranchNode->insert($branchNode);
			$branchNode = $parentBranchNode;
			$tempBranch = $tempBranch->getParentBranch();
			if (!$tempBranch) {
				$this->_root = $parentBranchNode;
			}
		}
	}

	/**
	 * Returns the tree's root node
	 *
	 * @return BranchGraphNode
	 */
	public function getRoot()
	{
		return $this->_root;
	}

}