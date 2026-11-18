import { MemberProducts } from '@/components/store/team/member-products';
import type { MemberProductsProps } from '@/components/store/team/member-products';

/**
 * A staff member's own products and how each one sells.
 */
export default function MyProducts(props: MemberProductsProps) {
    return <MemberProducts {...props} />;
}
