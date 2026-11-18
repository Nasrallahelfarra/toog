import { MemberProducts } from '@/components/store/team/member-products';
import type { MemberProductsProps } from '@/components/store/team/member-products';

/**
 * The owner's view of one staff member's products and their sales.
 */
export default function TeamMemberProducts(props: MemberProductsProps) {
    return <MemberProducts key={props.member.id} {...props} />;
}
