import { Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';

type SampleEntityShowEntity = {
    id: number | string;
    title?: unknown;
    body?: unknown;
    created_at?: string | null;
    updated_at?: string | null;
};

type SampleEntityShowPageProps = {
    moduleTitle: string;
    routePath: string;
    entity: SampleEntityShowEntity;
};

export default function SampleEntityShowPage({ moduleTitle, routePath, entity }: SampleEntityShowPageProps) {
    const destroy = () => {
        router.delete(`${routePath}/${String(entity.id)}`);
    };

    return (
        <AppLayout>
            <div className="space-y-6 p-4 sm:p-6">
                <Card>
                    <CardHeader>
                        <CardTitle>{moduleTitle} #{String(entity.id)}</CardTitle>
                        <CardDescription>Tenant-scoped details view.</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        <div className="text-sm"><span className="font-medium">Title:</span> <span className="text-muted-foreground">{String(entity.title ?? '-')}</span></div>
                        <div className="text-sm"><span className="font-medium">Body:</span> <span className="text-muted-foreground">{String(entity.body ?? '-')}</span></div>

                        <div className="flex flex-wrap gap-2 pt-4">
                            <Button variant="outline" asChild>
                                <Link href={routePath}>Back</Link>
                            </Button>
                            <Button variant="secondary" asChild>
                                <Link href={`${routePath}/${String(entity.id)}/edit`}>Edit</Link>
                            </Button>
                            <Button variant="destructive" type="button" onClick={destroy}>
                                Delete
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}