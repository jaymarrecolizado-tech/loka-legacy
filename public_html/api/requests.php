<?php
/**
 * LOKA - Requests API
 *
 * RESTful API endpoints for request operations
 * - cancel_request
 * - update_mileage
 * - update_documents
 */

header('Content-Type: application/json');

$action = get('action');

try {
    switch ($action) {
        case 'cancel_request':
            // Cancel a request at any stage
            requireAuth();
            requireCsrf();

            $requestId = getInt('id');

            // Get request
            $request = db()->fetch(
                "SELECT r.* FROM requests r WHERE r.id = ? AND r.deleted_at IS NULL",
                [$requestId]
            );

            if (!$request) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Request not found'
                ]);
                exit;
            }

            // Verify ownership
            if ($request->user_id != userId()) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Unauthorized'
                ]);
                exit;
            }

            // Allow cancel at any stage except completed or already cancelled
            if (in_array($request->status, [STATUS_COMPLETED, STATUS_CANCELLED])) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Cannot cancel ' . str_replace('_', ' ', $request->status) . ' requests'
                ]);
                exit;
            }

            // Start transaction
            db()->beginTransaction();

            try {
                // Update request status
                db()->update('requests', [
                    'status' => STATUS_CANCELLED,
                    'cancelled_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ], 'id = ?', [$requestId]);

                // If vehicle was assigned, free it
                if ($request->vehicle_id) {
                    db()->update('vehicles', [
                        'status' => 'available',
                        'updated_at' => date('Y-m-d H:i:s')
                    ], 'id = ?', [$request->vehicle_id]);
                }

                // If driver was assigned, free them
                if ($request->driver_id) {
                    db()->update('drivers', [
                        'status' => 'available',
                        'updated_at' => date('Y-m-d H:i:s')
                    ], 'id = ?', [$request->driver_id]);
                }

                auditLog('cancel', 'request', $requestId, null, ['status' => $request->status]);

                db()->commit();

                echo json_encode([
                    'success' => true,
                    'message' => 'Request cancelled successfully'
                ]);

            } catch (Exception $e) {
                db()->rollBack();
                throw $e;
            }
            break;

        case 'update_mileage':
            // Update mileage for a request
            requireAuth();
            requireCsrf();

            $requestId = getInt('id');
            $mileageStart = postInt('mileage_start') ?: null;
            $mileageEnd = postInt('mileage_end') ?: null;

            // Get request
            $request = db()->fetch(
                "SELECT r.*, v.mileage as vehicle_mileage
                 FROM requests r
                 LEFT JOIN vehicles v ON r.vehicle_id = v.id
                 WHERE r.id = ? AND r.deleted_at IS NULL",
                [$requestId]
            );

            if (!$request) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Request not found'
                ]);
                exit;
            }

            // Authorization check - only motorpool can update starting mileage, guard can update ending
            $isMotorpool = isMotorpool() || isAdmin();
            $isGuard = isGuard() || isAdmin();

            if ($mileageStart !== null) {
                if (!$isMotorpool) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Unauthorized - Only motorpool can set starting mileage'
                    ]);
                    exit;
                }

                // Validate starting mileage
                if ($mileageStart < ($request->vehicle_mileage ?? 0)) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Starting mileage cannot be less than vehicle current mileage'
                    ]);
                    exit;
                }
            }

            if ($mileageEnd !== null) {
                if (!$isGuard) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Unauthorized - Only guard can set ending mileage'
                    ]);
                    exit;
                }

                // Validate ending mileage
                if ($request->mileage_start && $mileageEnd < $request->mileage_start) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Ending mileage must be greater than or equal to starting mileage'
                    ]);
                    exit;
                }
            }

            // Calculate actual mileage
            $mileageActual = null;
            if ($mileageEnd !== null && $request->mileage_start !== null) {
                $mileageActual = $mileageEnd - $request->mileage_start;
            }

            // Update request
            $updateData = ['updated_at' => date('Y-m-d H:i:s')];

            if ($mileageStart !== null) {
                $updateData['mileage_start'] = $mileageStart;
            }
            if ($mileageEnd !== null) {
                $updateData['mileage_end'] = $mileageEnd;
            }
            if ($mileageActual !== null) {
                $updateData['mileage_actual'] = $mileageActual;
            }

            db()->update('requests', $updateData, 'id = ?', [$requestId]);

            // If trip is completed and mileage_end was provided, update vehicle mileage
            if ($mileageEnd !== null && $request->status === STATUS_COMPLETED && $request->vehicle_id) {
                db()->update('vehicles', [
                    'mileage' => $mileageEnd,
                    'updated_at' => date('Y-m-d H:i:s')
                ], 'id = ?', [$request->vehicle_id]);
            }

            auditLog('update_mileage', 'request', $requestId, null, [
                'mileage_start' => $mileageStart,
                'mileage_end' => $mileageEnd,
                'mileage_actual' => $mileageActual
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Mileage updated successfully',
                'data' => [
                    'mileage_start' => $mileageStart,
                    'mileage_end' => $mileageEnd,
                    'mileage_actual' => $mileageActual
                ]
            ]);
            break;

        case 'update_documents':
            // Update travel documents for a request
            requireAuth();
            requireCsrf();

            $requestId = getInt('id');
            $hasTravelOrder = post('has_travel_order') ? 1 : 0;
            $hasObSlip = post('has_official_business_slip') ? 1 : 0;
            $travelOrderNumber = null;
            $obSlipNumber = null;

            // Validate
            if ($hasTravelOrder) {
                $travelOrderNumber = postSafe('travel_order_number', '', 50);
                if (empty($travelOrderNumber)) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Travel Order number is required when checkbox is checked'
                    ]);
                    exit;
                }
            }

            if ($hasObSlip) {
                $obSlipNumber = postSafe('ob_slip_number', '', 50);
                if (empty($obSlipNumber)) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'OB Slip number is required when checkbox is checked'
                    ]);
                    exit;
                }
            }

            // Get request
            $request = db()->fetch(
                "SELECT * FROM requests WHERE id = ? AND r.deleted_at IS NULL",
                [$requestId]
            );

            if (!$request) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Request not found'
                ]);
                exit;
            }

            // Authorization check - only guard can update documents
            if (!isGuard() && !isAdmin()) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Unauthorized - Only guard can update travel documents'
                ]);
                exit;
            }

            // Update request
            db()->update('requests', [
                'has_travel_order' => $hasTravelOrder,
                'has_official_business_slip' => $hasObSlip,
                'travel_order_number' => $travelOrderNumber,
                'ob_slip_number' => $obSlipNumber,
                'updated_at' => date('Y-m-d H:i:s')
            ], 'id = ?', [$requestId]);

            auditLog('update_documents', 'request', $requestId, null, [
                'has_travel_order' => $hasTravelOrder,
                'has_official_business_slip' => $hasObSlip
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Travel documents updated successfully'
            ]);
            break;

        case 'get':
            // Get single request details
            requireAuth();

            $requestId = getInt('id');

            $request = db()->fetch(
                "SELECT r.*,
                        u.name as requester_name, u.email as requester_email,
                        d.name as department_name,
                        v.plate_number as vehicle_plate, v.make as vehicle_make, v.model as vehicle_model,
                        vt.name as vehicle_type,
                        dr.license_number as driver_license,
                        drv_u.name as driver_name,
                        app_u.name as approver_name,
                        mph_u.name as motorpool_head_name
                 FROM requests r
                 JOIN users u ON r.user_id = u.id
                 JOIN departments d ON r.department_id = d.id
                 LEFT JOIN vehicles v ON r.vehicle_id = v.id AND v.deleted_at IS NULL
                 LEFT JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
                 LEFT JOIN drivers dr ON r.driver_id = dr.id AND dr.deleted_at IS NULL
                 LEFT JOIN users drv_u ON dr.user_id = drv_u.id
                 LEFT JOIN users app_u ON r.approver_id = app_u.id
                 LEFT JOIN users mph_u ON r.motorpool_head_id = mph_u.id
                 WHERE r.id = ? AND r.deleted_at IS NULL",
                [$requestId]
            );

            if (!$request) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Request not found'
                ]);
                exit;
            }

            echo json_encode([
                'success' => true,
                'data' => $request
            ]);
            break;

        case 'list':
            // Get list of requests (with optional filters)
            requireAuth();

            $status = get('status');
            $startDate = get('start_date');
            $endDate = get('end_date');
            $limit = getInt('limit', 50);
            $offset = getInt('offset', 0);

            $sql = "SELECT r.*,
                        u.name as requester_name,
                        d.name as department_name,
                        v.plate_number as vehicle_plate,
                        v.make as vehicle_make, v.model as vehicle_model,
                        drv_u.name as driver_name
                 FROM requests r
                 JOIN users u ON r.user_id = u.id
                 JOIN departments d ON r.department_id = d.id
                 LEFT JOIN vehicles v ON r.vehicle_id = v.id AND v.deleted_at IS NULL
                 LEFT JOIN drivers dr ON r.driver_id = dr.id AND dr.deleted_at IS NULL
                 LEFT JOIN users drv_u ON dr.user_id = drv_u.id
                 WHERE r.deleted_at IS NULL";

            $params = [];

            if ($status) {
                $sql .= " AND r.status = ?";
                $params[] = $status;
            }

            if ($startDate && $endDate) {
                $sql .= " AND DATE(r.start_datetime) BETWEEN ? AND ?";
                $params[] = $startDate;
                $params[] = $endDate;
            }

            $sql .= " ORDER BY r.created_at DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;

            $requests = db()->fetchAll($sql, $params);

            echo json_encode([
                'success' => true,
                'data' => $requests
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
