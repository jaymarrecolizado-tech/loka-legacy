<?php
/**
 * LOKA - Vehicle Types API
 *
 * RESTful API endpoints for vehicle type CRUD operations
 */

requireRole(ROLE_APPROVER);

header('Content-Type: application/json');

$action = get('action');

try {
    switch ($action) {
        case 'list':
            // GET all vehicle types
            $vehicleTypes = db()->fetchAll(
                "SELECT vt.*,
                        (SELECT COUNT(*) FROM vehicles v WHERE v.vehicle_type_id = vt.id AND v.deleted_at IS NULL) as vehicle_count
                 FROM vehicle_types vt
                 WHERE vt.deleted_at IS NULL
                 ORDER BY vt.name"
            );

            echo json_encode([
                'success' => true,
                'data' => $vehicleTypes
            ]);
            break;

        case 'get':
            // GET single vehicle type
            $id = getInt('id');
            $vehicleType = db()->fetch(
                "SELECT vt.*,
                        (SELECT COUNT(*) FROM vehicles v WHERE v.vehicle_type_id = vt.id AND v.deleted_at IS NULL) as vehicle_count
                 FROM vehicle_types vt
                 WHERE vt.id = ? AND vt.deleted_at IS NULL",
                [$id]
            );

            if (!$vehicleType) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Vehicle type not found'
                ]);
                exit;
            }

            echo json_encode([
                'success' => true,
                'data' => $vehicleType
            ]);
            break;

        case 'create':
            // POST create new vehicle type
            requireCsrf();

            $name = postSafe('name', '', 50);
            $description = postSafe('description', '', 500);
            $passengerCapacity = postInt('passenger_capacity');

            // Validate
            if (empty($name)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Vehicle type name is required'
                ]);
                exit;
            }

            if ($passengerCapacity < 1 || $passengerCapacity > 50) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Passenger capacity must be between 1 and 50'
                ]);
                exit;
            }

            // Check if name already exists
            $existing = db()->fetch(
                "SELECT id FROM vehicle_types WHERE name = ? AND deleted_at IS NULL",
                [$name]
            );

            if ($existing) {
                echo json_encode([
                    'success' => false,
                    'message' => 'A vehicle type with this name already exists'
                ]);
                exit;
            }

            // Create vehicle type
            $id = db()->insert('vehicle_types', [
                'name' => $name,
                'description' => $description ?: null,
                'passenger_capacity' => $passengerCapacity,
                'created_at' => date('Y-m-d H:i:s')
            ]);

            auditLog('create', 'vehicle_type', $id, null, ['name' => $name]);

            echo json_encode([
                'success' => true,
                'message' => 'Vehicle type created successfully',
                'data' => ['id' => $id]
            ]);
            break;

        case 'update':
            // POST update vehicle type
            requireCsrf();

            $id = getInt('id');
            $name = postSafe('name', '', 50);
            $description = postSafe('description', '', 500);
            $passengerCapacity = postInt('passenger_capacity');

            // Check if vehicle type exists
            $vehicleType = db()->fetch(
                "SELECT * FROM vehicle_types WHERE id = ? AND deleted_at IS NULL",
                [$id]
            );

            if (!$vehicleType) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Vehicle type not found'
                ]);
                exit;
            }

            // Validate
            if (empty($name)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Vehicle type name is required'
                ]);
                exit;
            }

            if ($passengerCapacity < 1 || $passengerCapacity > 50) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Passenger capacity must be between 1 and 50'
                ]);
                exit;
            }

            // Check if name already exists (excluding current record)
            $existing = db()->fetch(
                "SELECT id FROM vehicle_types WHERE name = ? AND id != ? AND deleted_at IS NULL",
                [$name, $id]
            );

            if ($existing) {
                echo json_encode([
                    'success' => false,
                    'message' => 'A vehicle type with this name already exists'
                ]);
                exit;
            }

            // Update vehicle type
            db()->update('vehicle_types', [
                'name' => $name,
                'description' => $description ?: null,
                'passenger_capacity' => $passengerCapacity,
                'updated_at' => date('Y-m-d H:i:s')
            ], 'id = ?', [$id]);

            auditLog('update', 'vehicle_type', $id, null, ['name' => $name]);

            echo json_encode([
                'success' => true,
                'message' => 'Vehicle type updated successfully'
            ]);
            break;

        case 'delete':
            // POST delete (soft delete) vehicle type
            requireCsrf();

            $id = getInt('id');

            // Check if vehicle type exists
            $vehicleType = db()->fetch(
                "SELECT * FROM vehicle_types WHERE id = ? AND deleted_at IS NULL",
                [$id]
            );

            if (!$vehicleType) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Vehicle type not found'
                ]);
                exit;
            }

            // Check if vehicles are using this type
            $vehicleCount = db()->fetchColumn(
                "SELECT COUNT(*) FROM vehicles WHERE vehicle_type_id = ? AND deleted_at IS NULL",
                [$id]
            );

            if ($vehicleCount > 0) {
                echo json_encode([
                    'success' => false,
                    'message' => "Cannot delete: {$vehicleCount} vehicle(s) are using this type"
                ]);
                exit;
            }

            // Soft delete
            db()->update('vehicle_types', [
                'deleted_at' => date('Y-m-d H:i:s')
            ], 'id = ?', [$id]);

            auditLog('delete', 'vehicle_type', $id, null, ['name' => $vehicleType->name]);

            echo json_encode([
                'success' => true,
                'message' => 'Vehicle type deleted successfully'
            ]);
            break;

        default:
            echo json_encode([
                'success' => false,
                'message' => 'Invalid action'
            ]);
            break;
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
