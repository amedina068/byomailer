/**
 * Tree layout algorithm for visual automation workflows.
 *
 * Calculates non-overlapping positions for all nodes by:
 * 1. Building a tree from the graph (nodes + edges)
 * 2. Calculating subtree widths bottom-up
 * 3. Assigning positions top-down
 */

export interface WorkflowNode {
    id: string;
    type: string;
    position: { x: number; y: number };
    data?: Record<string, unknown>;
}

export interface WorkflowEdge {
    id: string;
    source: string;
    target: string;
    sourceHandle?: string | null;
    targetHandle?: string | null;
    label?: string | null;
}

interface TreeNode {
    id: string;
    type: string;
    data?: Record<string, unknown>;
    children: TreeNode[];
    subtreeWidth: number;
}

const NODE_WIDTH = 280;
const NODE_HEIGHT = 200;
const GAP_X = 60;
const GAP_Y = 80;

/**
 * Build a tree structure from the flat graph, starting at the trigger node.
 */
function buildTree(
    nodes: WorkflowNode[],
    edges: WorkflowEdge[],
): TreeNode | null {
    const nodeMap = new Map<string, WorkflowNode>();
    for (const node of nodes) {
        nodeMap.set(node.id, node);
    }

    const outgoing = new Map<string, WorkflowEdge[]>();
    for (const edge of edges) {
        if (!outgoing.has(edge.source)) {
            outgoing.set(edge.source, []);
        }
        outgoing.get(edge.source)!.push(edge);
    }

    const triggerNode = nodes.find((n) => n.type === 'trigger');
    if (!triggerNode) {
        return null;
    }

    const visited = new Set<string>();

    function buildSubtree(nodeId: string): TreeNode | null {
        if (visited.has(nodeId)) {
            return null;
        }
        visited.add(nodeId);

        const node = nodeMap.get(nodeId);
        if (!node) {
            return null;
        }

        const nodeEdges = outgoing.get(nodeId) ?? [];
        const children: TreeNode[] = [];

        if (node.type === 'condition') {
            const yesEdge = nodeEdges.find(
                (e) => e.sourceHandle === 'yes',
            );
            const noEdge = nodeEdges.find(
                (e) => e.sourceHandle === 'no',
            );

            if (yesEdge) {
                const child = buildSubtree(yesEdge.target);
                if (child) {
                    children.push(child);
                }
            }
            if (noEdge) {
                const child = buildSubtree(noEdge.target);
                if (child) {
                    children.push(child);
                }
            }
        } else {
            for (const edge of nodeEdges) {
                const child = buildSubtree(edge.target);
                if (child) {
                    children.push(child);
                }
            }
        }

        return {
            id: nodeId,
            type: node.type,
            data: node.data,
            children,
            subtreeWidth: 0,
        };
    }

    return buildSubtree(triggerNode.id);
}

/**
 * Calculate the width each subtree needs (bottom-up).
 * Condition nodes with no children need enough width for two placeholder buttons.
 */
function calculateWidths(tree: TreeNode): void {
    if (tree.children.length === 0) {
        // Condition nodes with 0 children need space for two placeholders side by side
        if (tree.type === 'condition') {
            tree.subtreeWidth = NODE_WIDTH * 2 + GAP_X;
        } else {
            tree.subtreeWidth = NODE_WIDTH;
        }
        return;
    }

    for (const child of tree.children) {
        calculateWidths(child);
    }

    if (tree.children.length === 1) {
        // Condition with 1 child still needs room for the missing branch placeholder
        if (tree.type === 'condition') {
            const childWidth = tree.children[0].subtreeWidth;
            const placeholderWidth = NODE_WIDTH;
            tree.subtreeWidth = Math.max(
                NODE_WIDTH,
                childWidth + GAP_X + placeholderWidth,
            );
        } else {
            tree.subtreeWidth = Math.max(
                NODE_WIDTH,
                tree.children[0].subtreeWidth,
            );
        }
    } else {
        let total = 0;
        for (let i = 0; i < tree.children.length; i++) {
            total += tree.children[i].subtreeWidth;
            if (i < tree.children.length - 1) {
                total += GAP_X;
            }
        }
        tree.subtreeWidth = Math.max(NODE_WIDTH, total);
    }
}

/**
 * Assign x,y positions (top-down).
 */
function assignPositions(
    tree: TreeNode,
    centerX: number,
    y: number,
    positions: Map<string, { x: number; y: number }>,
): void {
    positions.set(tree.id, {
        x: centerX - NODE_WIDTH / 2,
        y,
    });

    const childY = y + NODE_HEIGHT + GAP_Y;

    if (tree.children.length === 1) {
        if (tree.type === 'condition') {
            // Position the child on the appropriate side
            const halfWidth = tree.subtreeWidth / 2;
            const childCenter =
                centerX - halfWidth + tree.children[0].subtreeWidth / 2;
            assignPositions(
                tree.children[0],
                childCenter,
                childY,
                positions,
            );
        } else {
            assignPositions(tree.children[0], centerX, childY, positions);
        }
    } else if (tree.children.length >= 2) {
        let totalWidth = 0;
        for (let i = 0; i < tree.children.length; i++) {
            totalWidth += tree.children[i].subtreeWidth;
            if (i < tree.children.length - 1) {
                totalWidth += GAP_X;
            }
        }

        let currentX = centerX - totalWidth / 2;
        for (const child of tree.children) {
            const childCenterX = currentX + child.subtreeWidth / 2;
            assignPositions(child, childCenterX, childY, positions);
            currentX += child.subtreeWidth + GAP_X;
        }
    }
}

/**
 * Main layout function. Takes workflow nodes and edges, returns positioned nodes.
 */
export function layoutWorkflow(
    nodes: WorkflowNode[],
    edges: WorkflowEdge[],
): WorkflowNode[] {
    const tree = buildTree(nodes, edges);
    if (!tree) {
        return nodes;
    }

    calculateWidths(tree);

    const positions = new Map<string, { x: number; y: number }>();
    assignPositions(tree, 0, 0, positions);

    return nodes.map((node) => {
        const pos = positions.get(node.id);
        return {
            ...node,
            position: pos ?? node.position,
        };
    });
}

/**
 * Placeholder endpoint for "add step" buttons at the end of each branch.
 */
export interface PlaceholderEndpoint {
    id: string;
    sourceId: string;
    sourceHandle: string | null;
    label: string | null;
    position: { x: number; y: number };
    /** When set, this placeholder represents an insert point on an existing edge */
    insertOnEdgeId?: string;
}

/**
 * Calculate placeholder nodes for open endpoints (nodes with unused source handles)
 * and intermediate "insert" placeholders on existing edges.
 */
export function calculatePlaceholders(
    nodes: WorkflowNode[],
    edges: WorkflowEdge[],
): PlaceholderEndpoint[] {
    const endpoints: PlaceholderEndpoint[] = [];

    const hasConnection = (
        nodeId: string,
        handle: string | null,
    ): boolean =>
        edges.some(
            (edge) =>
                edge.source === nodeId &&
                (edge.sourceHandle ?? null) === handle,
        );

    // Find the node's positioned x center
    const nodeCenter = (node: WorkflowNode): number =>
        node.position.x + NODE_WIDTH / 2;

    for (const node of nodes) {
        const handles: Array<string | null> =
            node.type === 'condition' ? ['yes', 'no'] : [null];

        for (const handle of handles) {
            if (hasConnection(node.id, handle)) {
                continue;
            }

            if (node.type === 'trigger' && !node.data?.trigger_type) {
                continue;
            }

            // For condition placeholders, offset them based on the parent's subtree layout
            let placeholderX = node.position.x;

            if (node.type === 'condition') {
                const center = nodeCenter(node);
                const offset = (NODE_WIDTH + GAP_X) / 2;
                if (handle === 'yes') {
                    placeholderX = center - offset - NODE_WIDTH / 2;
                } else {
                    placeholderX = center + offset - NODE_WIDTH / 2;
                }
            }

            endpoints.push({
                id: `${node.id}-${handle ?? 'default'}-add`,
                sourceId: node.id,
                sourceHandle: handle,
                label:
                    node.type === 'condition'
                        ? handle === 'yes'
                            ? 'Yes'
                            : 'No'
                        : null,
                position: {
                    x: placeholderX,
                    y: node.position.y + NODE_HEIGHT + GAP_Y,
                },
            });
        }
    }

    // Add intermediate "insert" placeholders on existing edges
    const nodeMap = new Map<string, WorkflowNode>();
    for (const node of nodes) {
        nodeMap.set(node.id, node);
    }

    for (const edge of edges) {
        const sourceNode = nodeMap.get(edge.source);
        const targetNode = nodeMap.get(edge.target);
        if (!sourceNode || !targetNode) {
            continue;
        }

        // Don't show insert buttons on the trigger→first-node edge if trigger has only one child
        // Actually, show insert on all edges for maximum flexibility

        const midX =
            (sourceNode.position.x + targetNode.position.x) / 2 +
            NODE_WIDTH / 2;
        const midY =
            (sourceNode.position.y + targetNode.position.y) / 2 +
            NODE_HEIGHT / 2;

        endpoints.push({
            id: `insert-${edge.id}`,
            sourceId: edge.source,
            sourceHandle: edge.sourceHandle ?? null,
            label: null,
            insertOnEdgeId: edge.id,
            position: {
                x: midX - NODE_WIDTH / 2,
                y: midY - 20,
            },
        });
    }

    return endpoints;
}

export { NODE_WIDTH, NODE_HEIGHT, GAP_X, GAP_Y };
