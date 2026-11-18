export interface AzureToken {
    id: string;
    label: string;
    organization: string;
    token_hint: string;
    azure_display_name: string | null;
    is_default: boolean;
    is_expired: boolean;
    expires_at: string | null;
    last_used_at: string | null;
    last_validated_at: string | null;
    created_at: string | null;
}

export type PrRole = 'all' | 'author' | 'reviewer';
export type PrStatus = 'active' | 'completed' | 'abandoned' | 'all';

/** 10 aprovado · 5 aprovado c/ sugestões · 0 sem voto · -5 aguardando autor · -10 rejeitado */
export type ReviewVote = 10 | 5 | 0 | -5 | -10;

export interface PullRequestReviewer {
    id: string | null;
    name: string;
    vote: ReviewVote;
    is_required: boolean;
    is_group: boolean;
    has_declined: boolean;
}

export interface PullRequest {
    id: number;
    title: string;
    status: 'active' | 'completed' | 'abandoned';
    is_draft: boolean;
    merge_status: string | null;
    project: string;
    repository: string;
    source_branch: string;
    target_branch: string;
    author: string;
    created_at: string;
    closed_at: string | null;
    roles: ('author' | 'reviewer')[];
    my_vote: ReviewVote | null;
    awaiting_my_review: boolean;
    reviewers: PullRequestReviewer[];
    url: string;
    work_item: { id: number; url: string } | null;
}

export type WorkItemRole = 'all' | 'assigned' | 'created';
export type WorkItemTypeFilter = 'all' | 'pbi' | 'bug';
export type WorkItemStatus = 'open' | 'done' | 'removed' | 'all';
/** Categorias de estado do Azure DevOps (os nomes dos estados são personalizados por processo). */
export type WorkItemStateCategory = 'proposed' | 'in_progress' | 'resolved' | 'completed' | 'removed';

export interface WorkItemState {
    name: string;
    category: WorkItemStateCategory;
    /** Hex sem '#', como vem do Azure. */
    color: string | null;
}

export interface WorkItem {
    id: number;
    title: string;
    /** 'Product Backlog Item' | 'Bug' */
    type: string;
    state: string;
    state_category: WorkItemStateCategory;
    state_color: string | null;
    project: string;
    iteration: string | null;
    assigned_to: string | null;
    created_by: string | null;
    priority: number | null;
    tags: string[];
    roles: ('assigned' | 'created')[];
    created_at: string | null;
    changed_at: string | null;
    url: string;
}
