import { useApi } from '../ui/lib/hooks';

/**
 * Connection, policy and counts (GET admin/state).
 *
 * @return {Object} useApi result.
 */
export default function useStateApi() {
	return useApi( 'admin/state' );
}
