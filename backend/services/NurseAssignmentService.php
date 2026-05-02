

<?php
/**
 * NurseAssignmentService - Handles automatic nurse assignment logic for children
 * 
 * Responsibilities:
 * - Assign a nurse to a child using round-robin algorithm (least loaded nurse)
 * - Allow manual assignment by preferred nurse ID
 * - Reassign children if nurse is deleted or over capacity
 * - Get assignment statistics for load balancing
 * - Prevent duplicate assignments
 * 
 * This service is used by NurseController and AdminController for managing
 * nurse-child relationships.
 */

require_once __DIR__ . '/../models/NurseAssignment.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../helpers/Logger.php';

class NurseAssignmentService {
    private $assignmentModel;
    private $userModel;
    
    public function __construct() {
        $this->assignmentModel = new NurseAssignment();
        $this->userModel = new User();
    }
    
    /**
     * Assign a nurse to a child. If preferred nurse ID is provided, use it.
     * Otherwise, automatically assign the least loaded nurse (round-robin).
     * 
     * @param int $childId
     * @param int|null $preferredNurseId
     * @return int|false The assigned nurse ID or false on failure
     */
    public function assignNurse($childId, $preferredNurseId = null) {
        // Check if already assigned
        if ($this->assignmentModel->hasAssignment($childId)) {
            // If assigned and preferred nurse is different, reassign
            if ($preferredNurseId) {
                $current = $this->assignmentModel->getByChild($childId);
                if ($current && $current['nurse_id'] != $preferredNurseId) {
                    return $this->reassignNurse($childId, $preferredNurseId);
                }
            }
            // Already assigned correctly
            return $this->assignmentModel->getByChild($childId)['nurse_id'];
        }
        
        // If preferred nurse provided, use it
        if ($preferredNurseId) {
            $nurse = $this->userModel->findById($preferredNurseId);
            if ($nurse && $nurse['role_id'] == 2) {
                $this->assignmentModel->assign($childId, $preferredNurseId);
                Logger::info("Nurse manually assigned", $preferredNurseId, ['child_id' => $childId]);
                return $preferredNurseId;
            }
        }
        
        // Auto-assign using round-robin (least loaded nurse)
        $leastLoaded = $this->assignmentModel->getLeastLoadedNurse();
        if (!$leastLoaded) {
            Logger::error("No nurses available for assignment", null, ['child_id' => $childId]);
            return false;
        }
        
        $this->assignmentModel->assign($childId, $leastLoaded['nurse_id']);
        Logger::info("Nurse auto-assigned (round-robin)", $leastLoaded['nurse_id'], [
            'child_id' => $childId,
            'load' => $leastLoaded['assignment_count']
        ]);
        
        return $leastLoaded['nurse_id'];
    }
    
    /**
     * Reassign a child to a different nurse
     * 
     * @param int $childId
     * @param int $newNurseId
     * @return bool
     */
    public function reassignNurse($childId, $newNurseId) {
        $nurse = $this->userModel->findById($newNurseId);
        if (!$nurse || $nurse['role_id'] != 2) {
            return false;
        }
        
        $result = $this->assignmentModel->reassign($childId, $newNurseId);
        if ($result) {
            Logger::info("Child reassigned to nurse", $newNurseId, ['child_id' => $childId]);
        }
        return $result;
    }
    
    /**
     * Remove assignment for a child (e.g., when child is deleted)
     * 
     * @param int $childId
     * @return bool
     */
    public function removeAssignment($childId) {
        return $this->assignmentModel->removeAssignment($childId);
    }
    
    /**
     * Get the current nurse assigned to a child
     * 
     * @param int $childId
     * @return array|null
     */
    public function getAssignedNurse($childId) {
        return $this->assignmentModel->getByChild($childId);
    }
    
    /**
     * Get all children assigned to a specific nurse
     * 
     * @param int $nurseId
     * @return array
     */
    public function getChildrenByNurse($nurseId) {
        return $this->assignmentModel->getByNurse($nurseId);
    }
    
    /**
     * Get load balancing report (assignment counts per nurse)
     * 
     * @return array
     */
    public function getLoadBalancingReport() {
        $assignments = $this->assignmentModel->getAssignmentCount();
        $totalChildren = $this->assignmentModel->countTotal();
        $totalNurses = count($assignments);
        
        $ideal = $totalNurses > 0 ? ceil($totalChildren / $totalNurses) : 0;
        $max = 0;
        $min = PHP_INT_MAX;
        
        foreach ($assignments as $nurse) {
            $max = max($max, $nurse['assignment_count']);
            $min = min($min, $nurse['assignment_count']);
        }
        
        return [
            'assignments_per_nurse' => $assignments,
            'total_children' => $totalChildren,
            'total_nurses' => $totalNurses,
            'ideal_load' => $ideal,
            'max_load' => $max,
            'min_load' => $min,
            'balance_score' => $totalChildren > 0 && $max > 0 ? round((1 - ($max - $min) / $max) * 100, 2) : 100
        ];
    }
    
    /**
     * Redistribute children among nurses to achieve better balance
     * (Admin function - reassign children from overloaded to underloaded nurses)
     * 
     * @return array Results of redistribution (moved children count)
     */
    public function rebalanceLoad() {
        $nurses = $this->assignmentModel->getAssignmentCount();
        if (count($nurses) < 2) {
            return ['moved' => 0, 'message' => 'Need at least 2 nurses to rebalance'];
        }
        
        // Sort by assignment count (ascending)
        usort($nurses, function($a, $b) {
            return $a['assignment_count'] - $b['assignment_count'];
        });
        
        $leastLoaded = $nurses[0];
        $mostLoaded = $nurses[count($nurses) - 1];
        
        $difference = $mostLoaded['assignment_count'] - $leastLoaded['assignment_count'];
        $toMove = floor($difference / 2);
        
        if ($toMove <= 0) {
            return ['moved' => 0, 'message' => 'Load is already balanced'];
        }
        
        // Get children from most loaded nurse
        $children = $this->assignmentModel->getByNurse($mostLoaded['nurse_id']);
        $moved = 0;
        
        foreach ($children as $child) {
            if ($moved >= $toMove) break;
            if ($this->reassignNurse($child['id'], $leastLoaded['nurse_id'])) {
                $moved++;
            }
        }
        
        Logger::info("Load rebalanced by admin", null, [
            'from_nurse' => $mostLoaded['nurse_id'],
            'to_nurse' => $leastLoaded['nurse_id'],
            'moved_count' => $moved
        ]);
        
        return ['moved' => $moved, 'message' => "Moved $moved children to balance load"];
    }
}
?>